<?php

declare(strict_types=1);

namespace Modules\Sales;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Task reminders (tasks.remind_*) and the in-app notifications they
 * create (task_notifications, Sales migration 0011). Everything except
 * organizationsWithPendingReminders() is limited to the current
 * organization; who may see what is TaskService's / TaskReminderService's job.
 */
final class TaskNotificationRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'task_notifications';
    }

    // --- Reminder processing (cron) ----------------------------------------

    /**
     * Organizations with at least one reminder not handled yet (due or not).
     * The only cross-organization read; the processor then works inside each
     * organization (OrganizationContext::runAs) with its own clock.
     *
     * @return int[]
     */
    public function organizationsWithPendingReminders(): array
    {
        $rows = $this->findMany(
            "SELECT DISTINCT t.organization_id FROM tasks t JOIN organizations o ON o.id = t.organization_id
             WHERE o.status = 'active' AND t.remind_at IS NOT NULL AND t.remind_fired_seq < t.remind_seq
             ORDER BY t.organization_id"
        );

        return array_map(fn (array $r) => (int) $r['organization_id'], $rows);
    }

    /**
     * This organization's reminders due at $localNow (its local wall clock,
     * the same clock remind_at is stored in) that have not fired yet —
     * including those of archived, closed or deleted tasks, which the
     * processor marks as handled without notifying anyone.
     */
    public function dueReminders(string $localNow, int $limit): array
    {
        return $this->findMany(
            'SELECT * FROM tasks WHERE organization_id = :org_id AND remind_at IS NOT NULL AND remind_at <= :now
                AND remind_fired_seq < remind_seq ORDER BY remind_at, id LIMIT ' . max(1, $limit),
            ['org_id' => OrganizationContext::id(), 'now' => $localNow]
        );
    }

    /**
     * Claims one occurrence: true for exactly one caller, even when two
     * processors run at once (the row update is atomic), and false once
     * the reminder was handled or changed since it was read.
     */
    public function claim(int $taskId, int $occurrence): bool
    {
        return $this->query(
            'UPDATE tasks SET remind_fired_seq = :seq1
             WHERE id = :id AND organization_id = :org_id AND remind_seq = :seq2 AND remind_fired_seq < :seq3',
            ['seq1' => $occurrence, 'seq2' => $occurrence, 'seq3' => $occurrence, 'id' => $taskId, 'org_id' => OrganizationContext::id()]
        )->rowCount() === 1;
    }

    /**
     * One notification per task, user and occurrence; a repeat insert is
     * ignored (the UNIQUE key). With the task's email option on, its email
     * is queued with it ('pending'), so it too exists once per occurrence.
     */
    public function addReminder(array $task, int $userId): bool
    {
        return $this->query(
            "INSERT IGNORE INTO task_notifications (organization_id, user_id, task_id, kind, occurrence, remind_at, remind_all_day, email_status)
             VALUES (:org_id, :user_id, :task_id, 'reminder', :occurrence, :remind_at, :all_day, :email_status)",
            [
                'org_id' => OrganizationContext::id(),
                'user_id' => $userId,
                'task_id' => (int) $task['id'],
                'occurrence' => (int) $task['remind_seq'],
                'remind_at' => $task['remind_at'],
                'all_day' => (int) ($task['remind_all_day'] ?? 1),
                'email_status' => !empty($task['remind_email']) ? 'pending' : 'none',
            ]
        )->rowCount() === 1;
    }

    // --- Reminder emails (cron) -------------------------------------------------

    /** @return int[] organizations with emails waiting to be sent (or retried) */
    public function organizationsWithPendingEmails(): array
    {
        $rows = $this->findMany("SELECT DISTINCT organization_id FROM task_notifications WHERE email_status = 'pending' ORDER BY organization_id");

        return array_map(fn (array $r) => (int) $r['organization_id'], $rows);
    }

    /** This organization's emails due now ($utcNow), not claimed by a live run (a claim older than $staleBefore is dead). */
    public function pendingEmails(string $utcNow, string $staleBefore, int $limit): array
    {
        return $this->findMany(
            "SELECT * FROM task_notifications WHERE organization_id = :org_id AND email_status = 'pending'
                AND (email_next_at IS NULL OR email_next_at <= :now)
                AND (email_lock IS NULL OR email_locked_at < :stale)
             ORDER BY id LIMIT " . max(1, $limit),
            ['org_id' => OrganizationContext::id(), 'now' => $utcNow, 'stale' => $staleBefore]
        );
    }

    /** Claims one email for sending: true for exactly one caller. */
    public function claimEmail(int $id, string $token, string $utcNow, string $staleBefore): bool
    {
        return $this->query(
            "UPDATE task_notifications SET email_lock = :token, email_locked_at = :now
             WHERE id = :id AND organization_id = :org_id AND email_status = 'pending'
                AND (email_lock IS NULL OR email_locked_at < :stale)",
            ['token' => $token, 'now' => $utcNow, 'id' => $id, 'org_id' => OrganizationContext::id(), 'stale' => $staleBefore]
        )->rowCount() === 1;
    }

    /** Records the outcome of a claimed email and releases the claim (only the claim's holder can). */
    public function finishEmail(int $id, string $token, array $fields): bool
    {
        return $this->update(
            'task_notifications',
            $fields + ['email_lock' => null, 'email_locked_at' => null],
            'id = :id AND organization_id = :org_id AND email_lock = :token',
            ['id' => $id, 'org_id' => OrganizationContext::id(), 'token' => $token]
        ) === 1;
    }

    /** Runs $work in a transaction, or inside the caller's when one is already open. */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    // --- A user's notifications ----------------------------------------------

    /** The user's notifications, newest first (unread only when $unreadOnly). */
    public function forUser(int $userId, bool $unreadOnly, int $limit): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM task_notifications WHERE user_id = :user_id' . ($unreadOnly ? ' AND read_at IS NULL' : '')
            . ' ORDER BY id DESC LIMIT ' . max(1, $limit),
            ['user_id' => $userId]
        );
    }

    public function findForUser(int $id, int $userId): ?array
    {
        return $this->tenantFindOne(
            'SELECT * FROM task_notifications WHERE id = :id AND user_id = :user_id',
            ['id' => $id, 'user_id' => $userId]
        );
    }

    /** Marks unread notifications read: one ($id), a task's ($taskId) or all of the user's. */
    public function markRead(int $userId, ?int $id = null, ?int $taskId = null): int
    {
        $where = 'user_id = :user_id AND read_at IS NULL';
        $params = ['user_id' => $userId];
        if ($id !== null) {
            $where .= ' AND id = :id';
            $params['id'] = $id;
        }
        if ($taskId !== null) {
            $where .= ' AND task_id = :task_id';
            $params['task_id'] = $taskId;
        }

        return $this->tenantUpdate(['read_at' => date('Y-m-d H:i:s')], $where, $params);
    }
}
