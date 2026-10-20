<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\PlatformTicketService;
use App\Core\Exceptions\AuthorizationException;
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
 * Super Admin → Tickets (/admin/tickets): every organization's platform
 * tickets, waiting ones first; reply, close / reopen, change priority.
 */
final class PlatformTicketAdminController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly PlatformTicketService $tickets)
    {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = $this->superAdmin();
        $filters = ['status' => (string) $request->query('status', ''), 'q' => mb_substr(trim((string) $request->query('q', '')), 0, 100)];
        $pagination = Pagination::fromRequest($request, $this->tickets->adminCount($user, $filters));

        return $this->viewWithLayout('admin/tickets/index', 'layout', [
            'tickets' => $this->tickets->adminPage($user, $filters, $pagination->perPage, $pagination->offset()),
            'filters' => $filters,
            'pagination' => $pagination,
        ]);
    }

    public function show(Request $request): Response
    {
        $data = $this->tickets->showForAdmin($this->superAdmin(), (int) $request->attribute('id'));

        return $this->viewWithLayout('admin/tickets/show', 'layout', $data + ['priorities' => PlatformTicketService::PRIORITIES]);
    }

    public function reply(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $close = (string) $request->input('close', '') === '1';
        $failed = 0;
        $this->attempt(function () use ($id, $request, $close, &$failed): void {
            $failed = $this->tickets->replyAsPlatform($this->superAdmin(), $id, (string) $request->input('body', ''), $close, $request->files('attachments'));
        }, $close ? 'platform_tickets.admin.replied_closed' : 'platform_tickets.admin.replied');
        if ($failed > 0) {
            Session::setFlash('error', t('platform_tickets.error.attachment_not_saved', ['count' => (string) $failed]));
        }

        return $this->redirect('/admin/tickets/' . $id . '#reply');
    }

    public function status(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $status = (string) $request->input('status', '');
        $this->attempt(fn () => $this->tickets->setStatusAsPlatform($this->superAdmin(), $id, $status), $status === 'open' ? 'platform_tickets.admin.reopened' : 'platform_tickets.closed');

        return $this->redirect('/admin/tickets/' . $id);
    }

    public function priority(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->attempt(fn () => $this->tickets->setPriority($this->superAdmin(), $id, (string) $request->input('priority', '')), 'platform_tickets.admin.priority_saved');

        return $this->redirect('/admin/tickets/' . $id);
    }

    /** A file of this ticket; 404 unless it belongs to one of the ticket's messages (and the ticket to the viewer). */
    public function download(Request $request): Response
    {
        $found = $this->tickets->attachment($this->superAdmin(), (int) $request->attribute('id'), (int) $request->attribute('attachmentId'));
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

    private function attempt(callable $action, string $successKey): void
    {
        try {
            $action();
            Session::setFlash('success', t($successKey));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'platform_tickets.error.body')));
        }
    }

    private function superAdmin(): array
    {
        $user = CurrentUser::user();
        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }

        return $user;
    }
}
