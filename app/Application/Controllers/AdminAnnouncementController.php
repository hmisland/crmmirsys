<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\AdminAnnouncementRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * One-way announcements: the Super Admin sends to one or all org admins
 * (/admin/announcements, with a per-recipient "seen" report); org admins
 * read them from the bell in the top bar and mark them seen or remove them.
 */
final class AdminAnnouncementController extends BaseController
{
    public function __construct(TemplateEngine $templates, private readonly AdminAnnouncementRepository $announcements)
    {
        parent::__construct($templates);
    }

    // ---- Super Admin ----------------------------------------------------

    public function index(Request $request): Response
    {
        $this->requireSuperAdmin();

        $pagination = Pagination::fromRequest($request, $this->announcements->countSent());

        return $this->viewWithLayout('admin/announcements', 'layout', [
            'sent' => $this->announcements->sent($pagination->perPage, $pagination->offset()),
            'pagination' => $pagination,
            'orgAdmins' => $this->announcements->orgAdmins(),
            'old' => (array) json_decode((string) ($_SESSION['_announcement_old'] ?? '[]'), true),
            'fmt' => $this->formatter(),
        ]);
    }

    public function send(Request $request): Response
    {
        $user = $this->requireSuperAdmin();

        $title = trim((string) $request->input('title', ''));
        $body = trim((string) $request->input('body', ''));
        $audience = $request->input('audience') === 'single' ? 'single' : 'all';
        $recipientId = (int) $request->input('recipient_user_id', 0);

        $admins = $this->announcements->orgAdmins();
        if ($audience === 'single') {
            $admins = array_values(array_filter($admins, fn ($a) => (int) $a['id'] === $recipientId));
        }

        $error = match (true) {
            $title === '' || mb_strlen($title) > 200 => 'announcements.error_title',
            $body === '' || mb_strlen($body) > 5000 => 'announcements.error_body',
            $admins === [] => $audience === 'single' ? 'announcements.error_recipient' : 'announcements.error_no_admins',
            default => null,
        };

        if ($error !== null) {
            $_SESSION['_announcement_old'] = json_encode(compact('title', 'body', 'audience') + ['recipient_user_id' => $recipientId]);
            Session::setFlash('error', t($error));

            return $this->redirect('/admin/announcements');
        }

        unset($_SESSION['_announcement_old']);
        $this->announcements->create($title, $body, $audience, (int) $user['id'], $admins);
        Session::setFlash('success', t('announcements.sent_to', ['count' => count($admins)]));

        return $this->redirect('/admin/announcements');
    }

    public function show(Request $request): Response
    {
        $this->requireSuperAdmin();

        $announcement = $this->announcements->find((int) $request->attribute('id'));
        if ($announcement === null) {
            throw new NotFoundException();
        }

        return $this->viewWithLayout('admin/announcement-show', 'layout', [
            'announcement' => $announcement,
            'recipients' => $this->announcements->recipients((int) $announcement['id']),
            'fmt' => $this->formatter(),
        ]);
    }

    public function destroy(Request $request): Response
    {
        $this->requireSuperAdmin();

        $this->announcements->deleteAnnouncement((int) $request->attribute('id'));
        Session::setFlash('success', t('announcements.deleted'));

        return $this->redirect('/admin/announcements');
    }

    // ---- Org admin (recipient) -----------------------------------------

    public function inbox(Request $request): Response
    {
        $user = $this->requireUser();

        return $this->viewWithLayout('org/announcements', 'layout', [
            'messages' => $this->announcements->inbox((int) $user['id'], 200),
            'fmt' => $this->formatter(),
        ]);
    }

    public function markSeen(Request $request): Response
    {
        $user = $this->requireUser();
        $this->announcements->markSeen((int) $request->attribute('id'), (int) $user['id']);

        return $this->redirect($this->returnPath($request));
    }

    public function remove(Request $request): Response
    {
        $user = $this->requireUser();
        if ($this->announcements->removeForUser((int) $request->attribute('id'), (int) $user['id'])) {
            Session::setFlash('success', t('announcements.removed'));
        }

        return $this->redirect($this->returnPath($request));
    }

    // ---- Internals -----------------------------------------------------

    /** Back to the page the bell was used on; only local paths. */
    private function returnPath(Request $request): string
    {
        $path = (string) $request->input('return', '');

        return preg_match('#^/(?![/\\\\])#', $path) ? $path : '/announcements';
    }

    private function formatter(): LocaleFormatter
    {
        $translator = Locale::get();

        return new LocaleFormatter($translator !== null ? $translator->locale() : 'en');
    }

    private function requireSuperAdmin(): array
    {
        $user = CurrentUser::user();
        if ($user === null || empty($user['is_super_admin'])) {
            throw new AuthorizationException();
        }

        return $user;
    }

    private function requireUser(): array
    {
        $user = CurrentUser::user();
        if ($user === null) {
            throw new AuthorizationException();
        }

        return $user;
    }
}
