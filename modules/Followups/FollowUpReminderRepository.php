<?php

declare(strict_types=1);

namespace Modules\Followups;

use App\Infrastructure\Repositories\BaseRepository;

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

    /** Due now, still pending — what a periodic sweep (cron or on-demand check) would process. */
    public function duePending(string $asOf): array
    {
        return $this->findMany(
            "SELECT * FROM follow_up_reminders WHERE status = 'pending' AND scheduled_at <= :as_of",
            ['as_of' => $asOf]
        );
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
