<?php

declare(strict_types=1);

namespace Modules\InternalMail;

use App\Infrastructure\Repositories\BaseRepository;

final class MessageAttachmentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'message_attachments';
    }

    public function forMessage(int $messageId): array
    {
        return $this->findMany(
            'SELECT * FROM message_attachments WHERE message_id = :message_id ORDER BY id',
            ['message_id' => $messageId]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM message_attachments WHERE id = :id', ['id' => $id]);
    }

    public function create(array $fields): int
    {
        return $this->insertGetId($this->table(), $fields);
    }

    public function deleteAttachment(int $id): void
    {
        $this->delete($this->table(), 'id = :id', ['id' => $id]);
    }
}
