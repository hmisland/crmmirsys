<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;

/**
 * Notes, tags, and attachments behave identically regardless of which
 * CRM Core entity they're attached to — one controller keyed by a
 * {type} route segment (validated against an allow-list) instead of
 * three near-identical controllers. Per Phase 0 §18, this is exactly
 * the point of these being shared Core infrastructure rather than
 * something each entity reimplements.
 */
final class RecordSupportController extends BaseController
{
    private const ALLOWED_TYPES = ['contact', 'company', 'lead'];

    public function __construct(
        \App\Infrastructure\Theme\TemplateEngine $templates,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly AttachmentService $attachments,
    ) {
        parent::__construct($templates);
    }

    public function addNote(Request $request): Response
    {
        [$type, $id] = $this->subject($request);
        $user = CurrentUser::user();

        try {
            $this->notes->add($type, $id, (int) $user['id'], (string) $request->input('body', ''));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add note.');
        }

        return $this->redirect($this->backTo($type, $id));
    }

    public function deleteNote(Request $request): Response
    {
        $this->notes->delete((int) $request->attribute('noteId'));
        [$type, $id] = $this->subject($request);

        return $this->redirect($this->backTo($type, $id));
    }

    public function addTag(Request $request): Response
    {
        [$type, $id] = $this->subject($request);
        $user = CurrentUser::user();

        try {
            $this->tags->attach($type, $id, (string) $request->input('name', ''), (int) $user['id']);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add tag.');
        }

        return $this->redirect($this->backTo($type, $id));
    }

    public function removeTag(Request $request): Response
    {
        [$type, $id] = $this->subject($request);
        $this->tags->detach($type, $id, (int) $request->attribute('tagId'), (int) CurrentUser::id());

        return $this->redirect($this->backTo($type, $id));
    }

    public function uploadAttachment(Request $request): Response
    {
        [$type, $id] = $this->subject($request);
        $user = CurrentUser::user();
        $file = $request->file('file');

        if ($file === null) {
            Session::setFlash('error', 'Choose a file first.');

            return $this->redirect($this->backTo($type, $id));
        }

        try {
            $this->attachments->upload($type, $id, (int) $user['organization_id'], (int) $user['id'], $file);
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not upload file.');
        }

        return $this->redirect($this->backTo($type, $id));
    }

    public function deleteAttachment(Request $request): Response
    {
        [$type, $id] = $this->subject($request);
        $this->attachments->delete((int) $request->attribute('attachmentId'));

        return $this->redirect($this->backTo($type, $id));
    }

    public function downloadAttachment(Request $request): Response
    {
        $this->subject($request); // validates {type}, keeps the same URL shape as the other actions
        $attachmentId = (int) $request->attribute('attachmentId');

        $attachment = $this->attachments->forSubject(
            (string) $request->attribute('type'),
            (int) $request->attribute('id')
        );

        $match = null;
        foreach ($attachment as $row) {
            if ((int) $row['id'] === $attachmentId) {
                $match = $row;
                break;
            }
        }

        if ($match === null) {
            throw new NotFoundException();
        }

        $path = $this->attachments->absolutePath($match);

        if (!is_file($path)) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', $match['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $match['filename']) . '"');
    }

    /** @return array{0: string, 1: int} */
    private function subject(Request $request): array
    {
        $type = (string) $request->attribute('type');

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new NotFoundException();
        }

        return [$type, (int) $request->attribute('id')];
    }

    private function backTo(string $type, int $id): string
    {
        $plural = match ($type) {
            'company' => 'companies',
            default => $type . 's', // contact -> contacts, lead -> leads
        };

        return '/' . $plural . '/' . $id;
    }
}
