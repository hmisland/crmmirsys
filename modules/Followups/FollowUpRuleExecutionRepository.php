<?php

declare(strict_types=1);

namespace Modules\Followups;

use App\Infrastructure\Repositories\BaseRepository;

final class FollowUpRuleExecutionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'follow_up_rule_executions';
    }

    public function alreadyExecuted(int $ruleId, string $eventIdentifier): bool
    {
        return $this->findOne(
            'SELECT id FROM follow_up_rule_executions WHERE rule_id = :rule_id AND event_identifier = :event_identifier',
            ['rule_id' => $ruleId, 'event_identifier' => $eventIdentifier]
        ) !== null;
    }

    /**
     * Relies on the table's UNIQUE(rule_id, event_identifier)
     * constraint as the real guarantee — alreadyExecuted() above is a
     * fast-path check to skip work, but two concurrent requests could
     * both pass it before either inserts. A duplicate-key error from
     * this insert means another process won the race; the caller
     * should treat that as "already handled", not as a failure.
     */
    public function record(array $fields): int
    {
        return $this->insertGetId($this->table(), $fields);
    }
}
