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
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;

/**
 * Notes, tags, and attachments behave identically regardless of which
 * CRM Core entity they're attached to — one controller keyed by a
 * {type} route segment instead of three near-identical controllers.
 * Per Phase 0 §18, this is exactly the point of these being shared
 * Core infrastructure rather than something each entity reimplements.
 *
 * Every action proves, in this order and BEFORE any write:
 *   1. {type} is a type in the RecordTypeRegistry the current user can
 *      view (Core: contact/company/lead; modules register their own);
 *   2. the parent {type} #{id} exists in the user's organization;
 *   3. the note / attachment / tag in the URL really belongs to that
 *      parent (and to the organization).
 * Anything else is a 404, same as an unknown type always was.
 */
final class RecordSupportController extends BaseController
{
    public function __construct(
        \App\Infrastructure\Theme\TemplateEngine $templates,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly AttachmentService $attachments,
        private readonly RecordTypeRegistry $recordTypes,
        private readonly ContactRepository $contactRepository = new ContactRepository(),
        private readonly CompanyRepository $companyRepository = new CompanyRepository(),
        private readonly LeadRepository $leadRepository = new LeadRepository(),
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
        [$type, $id] = $this->subject($request);
        $this->notes->deleteFromSubject($type, $id, (int) $request->attribute('noteId'));

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
        $this->tags->detachFromSubject($type, $id, (int) $request->attribute('tagId'), (int) CurrentUser::id());

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
        $this->attachments->deleteFromSubject($type, $id, (int) $request->attribute('attachmentId'));

        return $this->redirect($this->backTo($type, $id));
    }

    public function downloadAttachment(Request $request): Response
    {
        [$type, $id] = $this->subject($request);
        $match = $this->attachments->findForSubject($type, $id, (int) $request->attribute('attachmentId'));

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

    /**
     * Steps 1 + 2 of the class rule: a viewable type with record support,
     * and a parent that exists in the user's organization.
     *
     * @return array{0: string, 1: int}
     */
    private function subject(Request $request): array
    {
        $type = (string) $request->attribute('type');

        if (!$this->recordTypes->canView($type, CurrentUser::user()) || !$this->recordTypes->get($type)->recordSupport) {
            throw new NotFoundException();
        }

        $id = (int) $request->attribute('id');
        $this->requireParent($type, $id);

        return [$type, $id];
    }

    /**
     * The parent must exist in the user's organization. Core types are
     * read through Core's own tenant-scoped repositories; module types
     * through the finder the owning module registered (tenant-scoped too,
     * re-checked here like RecordTypeRegistry::find()). This is an
     * existence check only — record-level scope stays each page's job, so
     * nothing a user can see today becomes unusable. A type without a
     * finder can't be verified → 404.
     */
    private function requireParent(string $type, int $id): void
    {
        $row = null;

        if ($id > 0) {
            $row = match ($type) {
                'contact' => $this->contactRepository->findById($id),
                'company' => $this->companyRepository->findById($id),
                'lead' => $this->leadRepository->findById($id),
                default => ($finder = $this->recordTypes->get($type)?->finder) !== null ? $finder($id) : null,
            };
        }

        $user = CurrentUser::user();
        $foreign = is_array($row) && empty($user['is_super_admin']) && isset($row['organization_id'])
            && (int) $row['organization_id'] !== (int) ($user['organization_id'] ?? 0);

        if (!is_array($row) || $foreign) {
            throw new NotFoundException();
        }
    }

    private function backTo(string $type, int $id): string
    {
        return $this->recordTypes->get($type)?->showUrl($id) ?? '/';
    }
}
