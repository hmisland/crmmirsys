<?php

declare(strict_types=1);

namespace Modules\Followups;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Time\OrganizationTimezone;

final class FollowUpReminderRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'follow_up_reminders';
    }

    public function forFollowUp(int $followUpId): array
    {
        return $this->findMany(
            'SELECT * FROM follow_up_reminders WHERE follow_up_id = :follow_up_id ORDER BY scheduled_at',
            ['follow_up_id' => $followUpId]
        );
    }

    /**
     * Due now, still pending — what a periodic sweep (cron or on-demand
     * check) would process, across organizations. scheduled_at is the
     * follow-up organization's local wall clock, so "now" is that
     * organization's local time (rows carry organization_id).
     */
    public function duePending(?OrganizationTimezone $timezone = null): array
    {
        $timezone ??= OrganizationTimezone::shared();
        // Cheap pre-filter: no zone is more than 14 h ahead of UTC.
        $rows = $this->findMany(
            "SELECT r.*, f.organization_id FROM follow_up_reminders r
             INNER JOIN follow_ups f ON f.id = r.follow_up_id
             WHERE r.status = 'pending' AND r.scheduled_at <= :latest ORDER BY r.scheduled_at",
            ['latest' => $timezone->now()->setTimezone(new \DateTimeZone('UTC'))->modify('+14 hours')->format('Y-m-d H:i:s')]
        );

        return array_values(array_filter($rows, fn (array $r) => $r['scheduled_at'] <= $timezone->localNow((int) $r['organization_id'])));
    }

    public function create(array $fields): int
    {
        return $this->insertGetId($this->table(), $fields);
    }

    public function markSent(int $id): void
    {
        $this->update($this->table(), ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $id]);
    }

    public function deleteAllFor(int $followUpId): void
    {
        $this->delete($this->table(), 'follow_up_id = :follow_up_id', ['follow_up_id' => $followUpId]);
    }
}
