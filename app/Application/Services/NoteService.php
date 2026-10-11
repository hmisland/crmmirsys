<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\NotFoundException;
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

    /**
     * Deletes by id with no parent check — only for callers that have
     * already proven the note belongs to their record (ClientRecords).
     * Route-facing code uses deleteFromSubject().
     */
    public function delete(int $noteId): void
    {
        $this->notes->deleteNote($noteId);
    }

    /**
     * Deletes a note only if it really belongs to $type #$id (and, via the
     * tenant-scoped lookup, to the caller's organization); otherwise 404
     * and nothing is deleted. Never trust the note id from a URL alone.
     */
    public function deleteFromSubject(string $type, int $id, int $noteId): void
    {
        $note = $this->notes->findById($noteId);

        if ($note === null || (string) $note['notable_type'] !== $type || (int) $note['notable_id'] !== $id) {
            throw new NotFoundException();
        }

        $this->notes->deleteNote($noteId);
    }
}
