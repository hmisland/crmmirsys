<?php

declare(strict_types=1);

namespace Modules\Support\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\AttachmentService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Support\Services\TicketCategoryService;
use Modules\Support\Services\TicketReplyService;
use Modules\Support\Services\TicketService;

final class TicketController extends BaseController
{
    private const STATUSES = ['open', 'pending', 'resolved', 'closed'];

    public function __construct(
        TemplateEngine $templates,
        private readonly TicketService $tickets,
        private readonly TicketReplyService $replies,
        private readonly TicketCategoryService $categories,
        private readonly ContactRepository $contacts,
        private readonly UserRepository $users,
        private readonly AttachmentService $attachments,
    ) {
        parent::__construct($templates);
    }

    public function board(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $tickets = $this->tickets->list($actingUser);

        $byStatus = [];
        foreach ($tickets as $ticket) {
            $byStatus[$ticket['status']][] = $ticket;
        }

        return $this->viewWithLayout('support/board', 'layout', [
            'statuses' => self::STATUSES,
            'ticketsByStatus' => $byStatus,
        ]);
    }

    public function index(Request $request): Response
    {
        $filters = [
            'search' => (string) $request->query('q', ''),
            'status' => (string) $request->query('status', ''),
        ];

        return $this->viewWithLayout('support/index', 'layout', [
            'tickets' => $this->tickets->list(CurrentUser::user(), $filters),
            'q' => $filters['search'],
            'status' => $filters['status'],
            'statuses' => self::STATUSES,
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->viewWithLayout('support/form', 'layout', [
            'ticket' => null,
            'contacts' => $this->contacts->search([]),
            'categories' => $this->categories->all(),
            'users' => $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $ticket = $this->tickets->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('support/form', 'layout', [
                'ticket' => null,
                'contacts' => $this->contacts->search([]),
                'categories' => $this->categories->all(),
                'users' => $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        return $this->redirect('/support/tickets/' . $ticket['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $ticket = $this->tickets->find(CurrentUser::user(), $id);
        $contact = $ticket['contact_id'] !== null ? $this->contacts->findById((int) $ticket['contact_id']) : null;

        return $this->viewWithLayout('support/show', 'layout', [
            'ticket' => $ticket,
            'contact' => $contact,
            'replies' => $this->replies->forTicket($id),
            'attachments' => $this->attachments->forSubject('ticket', $id),
            'statuses' => self::STATUSES,
            'users' => $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $ticket = $this->tickets->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('support/form', 'layout', [
            'ticket' => $ticket,
            'contacts' => $this->contacts->search([]),
            'categories' => $this->categories->all(),
            'users' => $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tickets->update(CurrentUser::user(), $id, $request->all());
            Session::setFlash('success', 'Ticket updated.');
        } catch (ValidationException $e) {
            return $this->viewWithLayout('support/form', 'layout', [
                'ticket' => $this->tickets->find(CurrentUser::user(), $id),
                'contacts' => $this->contacts->search([]),
                'categories' => $this->categories->all(),
                'users' => $this->users->findAllInOrganization((int) CurrentUser::user()['organization_id']),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        return $this->redirect('/support/tickets/' . $id);
    }

    public function changeStatus(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->tickets->changeStatus(CurrentUser::user(), $id, (string) $request->input('status', ''));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not update status.');
        }

        return $this->redirect((string) $request->input('return_to', '/support/tickets/' . $id));
    }

    public function assign(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $assigneeId = (string) $request->input('assigned_to_user_id', '');

        $this->tickets->assign(CurrentUser::user(), $id, $assigneeId !== '' ? (int) $assigneeId : null);

        return $this->redirect('/support/tickets/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->tickets->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Ticket deleted.');

        return $this->redirect('/support/tickets');
    }

    public function addReply(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->replies->add(
                CurrentUser::user(),
                $id,
                (string) $request->input('body', ''),
                $request->input('is_internal') === '1'
            );
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add reply.');
        }

        return $this->redirect('/support/tickets/' . $id);
    }

    public function uploadAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $file = $request->file('file');
        $actingUser = CurrentUser::user();

        if ($file !== null) {
            try {
                $this->attachments->upload('ticket', $id, (int) $actingUser['organization_id'], (int) $actingUser['id'], $file);
            } catch (ValidationException $e) {
                Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not upload file.');
            }
        }

        return $this->redirect('/support/tickets/' . $id);
    }

    public function downloadAttachment(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $attachmentId = (int) $request->attribute('attachmentId');

        $match = null;
        foreach ($this->attachments->forSubject('ticket', $id) as $row) {
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

    public function deleteAttachment(Request $request): Response
    {
        $this->attachments->delete((int) $request->attribute('attachmentId'));

        return $this->redirect('/support/tickets/' . (int) $request->attribute('id'));
    }
}
