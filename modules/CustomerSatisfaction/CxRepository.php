<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * All cx_* tables (Customer Satisfaction migration 0001). Every
 * organization-facing statement carries organization_id from
 * OrganizationContext. The only cross-organization reads are the
 * dispatcher's dueInvitations() / recoverStaleSending() / expire(),
 * which return each row's own organization_id for the caller to run under.
 */
final class CxRepository extends BaseRepository
{
    // ---- surveys -------------------------------------------------------

    public function surveys(): array
    {
        return $this->findMany(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM cx_responses r WHERE r.survey_id = s.id) AS response_count,
                    (SELECT AVG(r.score) FROM cx_responses r WHERE r.survey_id = s.id) AS average_score,
                    (SELECT COUNT(*) FROM cx_invitations i WHERE i.survey_id = s.id) AS invitation_count
               FROM cx_surveys s WHERE s.organization_id = :org ORDER BY s.id DESC',
            ['org' => OrganizationContext::id()]
        );
    }

    public function survey(int $id): ?array
    {
        return $this->findOne('SELECT * FROM cx_surveys WHERE id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    /** Active surveys listening to $event (e.g. "order.completed") in the current organization. */
    public function triggeredSurveys(string $event): array
    {
        return $this->findMany(
            "SELECT * FROM cx_surveys WHERE organization_id = :org AND status = 'active' AND trigger_event = :e ORDER BY id",
            ['org' => OrganizationContext::id(), 'e' => $event]
        );
    }

    public function createSurvey(array $fields): int
    {
        return $this->insertGetId('cx_surveys', ['organization_id' => OrganizationContext::id()] + $fields);
    }

    public function updateSurvey(int $id, array $fields): void
    {
        $this->update('cx_surveys', $fields, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    public function questions(int $surveyId, bool $activeOnly = true): array
    {
        return $this->findMany(
            'SELECT * FROM cx_survey_questions WHERE survey_id = :s AND organization_id = :org' . ($activeOnly ? ' AND active = 1' : '') . ' ORDER BY position, id',
            ['s' => $surveyId, 'org' => OrganizationContext::id()]
        );
    }

    public function addQuestion(int $surveyId, array $fields): int
    {
        return $this->insertGetId('cx_survey_questions', ['organization_id' => OrganizationContext::id(), 'survey_id' => $surveyId] + $fields);
    }

    public function updateQuestion(int $id, array $fields): void
    {
        $this->update('cx_survey_questions', $fields, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    public function responseCount(int $surveyId): int
    {
        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM cx_responses WHERE survey_id = :s AND organization_id = :org', ['s' => $surveyId, 'org' => OrganizationContext::id()])['c'] ?? 0);
    }

    // ---- invitations ---------------------------------------------------

    /** INSERT IGNORE on UNIQUE(survey_id, context_ref): false = this context was already invited. */
    public function createInvitation(array $fields): bool
    {
        $fields = ['organization_id' => OrganizationContext::id()] + $fields;
        $columns = array_keys($fields);
        $sql = 'INSERT IGNORE INTO cx_invitations (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')';

        return $this->query($sql, $fields)->rowCount() === 1;
    }

    public function invitation(int $id): ?array
    {
        return $this->findOne('SELECT * FROM cx_invitations WHERE id = :id AND organization_id = :org', ['id' => $id, 'org' => OrganizationContext::id()]);
    }

    /** Dispatcher: due invitations of every organization, oldest first. */
    public function dueInvitations(int $limit): array
    {
        return $this->findMany(
            "SELECT * FROM cx_invitations WHERE status IN ('scheduled', 'waiting_channel') AND send_after <= :now ORDER BY send_after, id LIMIT " . max(1, $limit),
            ['now' => date('Y-m-d H:i:s')]
        );
    }

    /** Atomic claim before sending: only one run can move it to "sending". */
    public function claimInvitation(int $id, int $organizationId): bool
    {
        return $this->query(
            "UPDATE cx_invitations SET status = 'sending', attempts = attempts + 1 WHERE id = :id AND organization_id = :org AND status IN ('scheduled', 'waiting_channel')",
            ['id' => $id, 'org' => $organizationId]
        )->rowCount() === 1;
    }

    public function updateInvitation(int $id, int $organizationId, array $fields): void
    {
        $this->update('cx_invitations', $fields, 'id = :id AND organization_id = :org', ['id' => $id, 'org' => $organizationId]);
    }

    /**
     * A run that died after claiming: the message may or may not have
     * gone out, so it is NOT re-sent (at most once) — marked failed.
     */
    public function recoverStaleSending(int $olderThanSeconds = 1800): int
    {
        return $this->query(
            "UPDATE cx_invitations SET status = 'failed', last_error = 'cx.error.delivery_unknown' WHERE status = 'sending' AND updated_at < :before",
            ['before' => date('Y-m-d H:i:s', time() - $olderThanSeconds)]
        )->rowCount();
    }

    /** Sent but unanswered past the link validity, or waiting for a channel for over 7 days. */
    public function expire(): int
    {
        $now = date('Y-m-d H:i:s');
        $a = $this->query(
            "UPDATE cx_invitations i JOIN cx_surveys s ON s.id = i.survey_id
                SET i.status = 'expired'
              WHERE i.status = 'sent' AND i.sent_at < DATE_SUB(:now, INTERVAL s.link_valid_days DAY)",
            ['now' => $now]
        )->rowCount();
        $b = $this->query(
            "UPDATE cx_invitations SET status = 'expired', last_error = 'cx.error.no_channel' WHERE status = 'waiting_channel' AND created_at < :before",
            ['before' => date('Y-m-d H:i:s', time() - 7 * 86400)]
        )->rowCount();

        return $a + $b;
    }

    public function recentInvitations(int $limit = 20): array
    {
        return $this->findMany(
            'SELECT i.id, i.status, i.channel, i.context_label, i.recipient_name, i.send_after, i.sent_at, i.last_error, s.name AS survey_name
               FROM cx_invitations i JOIN cx_surveys s ON s.id = i.survey_id
              WHERE i.organization_id = :org ORDER BY i.id DESC LIMIT ' . max(1, $limit),
            ['org' => OrganizationContext::id()]
        );
    }

    // ---- responses -----------------------------------------------------

    public function createResponse(array $fields): int
    {
        return $this->insertGetId('cx_responses', ['organization_id' => OrganizationContext::id()] + $fields);
    }

    public function addAnswer(int $responseId, int $questionId, ?int $numeric, ?string $text): void
    {
        $this->insertGetId('cx_response_answers', [
            'organization_id' => OrganizationContext::id(),
            'response_id' => $responseId,
            'question_id' => $questionId,
            'numeric_value' => $numeric,
            'text_value' => $text,
        ]);
    }

    public function recentResponses(int $limit = 20, bool $openFollowUpOnly = false): array
    {
        return $this->findMany(
            'SELECT r.id, r.score, r.sentiment, r.comment, r.follow_up_status, r.submitted_at,
                    s.name AS survey_name, s.type AS survey_type, i.recipient_name, i.context_label, i.contact_id
               FROM cx_responses r
               JOIN cx_surveys s ON s.id = r.survey_id
               LEFT JOIN cx_invitations i ON i.id = r.invitation_id
              WHERE r.organization_id = :org' . ($openFollowUpOnly ? " AND r.follow_up_status = 'open'" : '') . '
              ORDER BY r.id DESC LIMIT ' . max(1, $limit),
            ['org' => OrganizationContext::id()]
        );
    }

    public function closeFollowUp(int $responseId, int $userId): bool
    {
        return $this->query(
            "UPDATE cx_responses SET follow_up_status = 'done', follow_up_by_user_id = :u, follow_up_at = :now
              WHERE id = :id AND organization_id = :org AND follow_up_status = 'open'",
            ['u' => $userId, 'now' => date('Y-m-d H:i:s'), 'id' => $responseId, 'org' => OrganizationContext::id()]
        )->rowCount() === 1;
    }

    public function atomic(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }
}
