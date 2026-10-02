<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\NoteRepository;
use App\Infrastructure\Validation\Validator;

final class NoteService
{
    public function __construct(
        private readonly NoteRepository $notes,
        private readonly TimelineService $timeline,
    ) {
    }

    public function forSubject(string $type, int $id): array
    {
        return $this->notes->forSubject($type, $id);
    }

    public function add(string $type, int $id, int $userId, string $body): array
    {
        $validator = Validator::make(['body' => $body], ['body' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $noteId = $this->notes->create($type, $id, $userId, trim($body));
        $this->timeline->record($type, $id, 'note_added', 'Added a note', $userId);

        return $this->notes->findById($noteId);
    }

    public function delete(int $noteId): void
    {
        $this->notes->deleteNote($noteId);
    }
}
