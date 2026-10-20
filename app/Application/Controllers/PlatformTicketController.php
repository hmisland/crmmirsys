<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\PlatformTicketService;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Organization side of platform tickets (/org/tickets): an organization
 * admin (organization.edit — route + service) writes to the platform team
 * and follows the thread. Only the organization's own tickets.
 */
final class PlatformTicketController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly PlatformTicketService $tickets)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = (array) CurrentUser::user();
        $status = (string) $request->query('status', '');
        $pagination = Pagination::fromRequest($request, $this->tickets->organizationCount($user, $status));

        return $this->viewWithLayout('org/tickets/index', 'layout', [
            'tickets' => $this->tickets->organizationPage($user, $status, $pagination->perPage, $pagination->offset()),
            'status' => $status,
            'pagination' => $pagination,
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form([], []);
    }

    public function store(Request $request): Response
    {
        $failed = 0;
        try {
            $id = $this->tickets->create((array) CurrentUser::user(), $request->all(), $request->files('attachments'), $failed);
        } catch (ValidationException $e) {
            return $this->form($e->errors, $request->all());
        }
        Session::setFlash('success', t('platform_tickets.created', ['id' => (string) $id]));
        if ($failed > 0) {
            Session::setFlash('error', t('platform_tickets.error.attachment_not_saved', ['count' => (string) $failed]));
        }

        return $this->redirect('/org/tickets/' . $id);
    }

    public function show(Request $request): Response
    {
        $data = $this->tickets->showForOrganization((array) CurrentUser::user(), (int) $request->attribute('id'));

        return $this->viewWithLayout('org/tickets/show', 'layout', $data);
    }

    public function reply(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $failed = $this->tickets->replyAsOrganization((array) CurrentUser::user(), $id, (string) $request->input('body', ''), $request->files('attachments'));
            Session::setFlash('success', t('platform_tickets.reply_sent'));
            if ($failed > 0) {
                Session::setFlash('error', t('platform_tickets.error.attachment_not_saved', ['count' => (string) $failed]));
            }
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'platform_tickets.error.body')));
        }

        return $this->redirect('/org/tickets/' . $id . '#reply');
    }

    public function close(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $this->tickets->closeAsOrganization((array) CurrentUser::user(), $id);
            Session::setFlash('success', t('platform_tickets.closed'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'platform_tickets.error.already_closed')));
        }

        return $this->redirect('/org/tickets/' . $id);
    }

    /** A file of this ticket; 404 unless it belongs to one of the ticket's messages (and the ticket to the viewer). */
    public function download(Request $request): Response
    {
        $found = $this->tickets->attachment((array) CurrentUser::user(), (int) $request->attribute('id'), (int) $request->attribute('attachmentId'));
        if ($found === null) {
            throw new NotFoundException();
        }
        $attachment = $found['attachment'];
        $name = str_replace(['"', "\r", "\n"], '', (string) $attachment['filename']);

        return Response::html((string) file_get_contents($found['path']))
            ->withHeader('Content-Type', (string) $attachment['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode((string) $attachment['filename']))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private function form(array $errors, array $old): Response
    {
        return $this->viewWithLayout('org/tickets/form', 'layout', [
            'errors' => $errors,
            'old' => $old,
            'categories' => PlatformTicketService::CATEGORIES,
            'priorities' => PlatformTicketService::PRIORITIES,
        ]);
    }
}
