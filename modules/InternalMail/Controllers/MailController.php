<?php

declare(strict_types=1);

namespace Modules\InternalMail\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Container;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\InternalMail\Services\MessageService;
use Modules\Notification\Services\NotificationDispatchService;

use function App\Infrastructure\Theme\t;

final class MailController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly MessageService $messages,
        private readonly UserRepository $users,
        private readonly Container $container,
    ) {
        parent::__construct($templates);
    }

    public function inbox(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $unread = $request->query('unread') === '1';
        $items = array_filter($this->messages->inbox(CurrentUser::user()), fn ($i) => (!$unread || !$i['is_read']) && $this->matches($q, [$i['subject'] ?? '', $i['sender_name'] ?? '']));
        [$items, $pagination] = Pagination::slice($items, $request);

        return $this->viewWithLayout('mail/inbox', 'layout', [
            'items' => $items,
            'pagination' => $pagination,
            'q' => $q,
            'unread' => $unread,
        ]);
    }

    public function sent(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $items = array_filter($this->messages->sent(CurrentUser::user()), fn ($i) => $this->matches($q, [$i['subject'] ?? '', $i['sender_name'] ?? '']));
        [$items, $pagination] = Pagination::slice($items, $request);

        return $this->viewWithLayout('mail/sent', 'layout', [
            'items' => $items,
            'pagination' => $pagination,
            'q' => $q,
        ]);
    }

    public function drafts(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $items = array_filter($this->messages->drafts(CurrentUser::user()), fn ($i) => $this->matches($q, [$i['subject'] ?? '', $i['sender_name'] ?? '']));
        [$items, $pagination] = Pagination::slice($items, $request);

        return $this->viewWithLayout('mail/drafts', 'layout', [
            'items' => $items,
            'pagination' => $pagination,
            'q' => $q,
        ]);
    }

    public function trash(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $folders = $this->messages->trash(CurrentUser::user());
        foreach ($folders as $key => $rows) {
            $folders[$key] = array_values(array_filter($rows, fn ($i) => $this->matches($q, [$i['subject'] ?? '', $i['sender_name'] ?? ''])));
        }

        return $this->viewWithLayout('mail/trash', 'layout', [
            'folders' => $folders,
            'q' => $q,
        ]);
    }

    /** Case-insensitive "contains" over a few text fields; an empty query matches everything. */
    private function matches(string $q, array $fields): bool
    {
        if ($q === '') {
            return true;
        }

        foreach ($fields as $field) {
            if (mb_stripos((string) $field, $q) !== false) {
                return true;
            }
        }

        return false;
    }

    public function showCompose(Request $request): Response
    {
        return $this->composeForm(null, [], [], $this->linkableFromQuery($request));
    }

    public function compose(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        [$to, $cc, $bcc] = $this->parseRecipients($request);
        [$linkableType, $linkableId] = $this->linkableFromInput($request);
        $files = $request->files('attachments');

        if ((string) $request->input('intent', 'send') === 'save_draft') {
            try {
                $draft = $this->messages->saveDraft($actingUser, null, $to, $cc, $bcc, (string) $request->input('subject', ''), (string) $request->input('body', ''), $linkableType, $linkableId, $files);
            } catch (ValidationException $e) {
                return $this->composeForm(null, $e->errors, $request->all(), [$linkableType, $linkableId]);
            }
            Session::setFlash('success', t('mail.flash.draft_saved'));

            return $this->redirect('/mail/drafts/' . $draft['id'] . '/edit');
        }

        try {
            $message = $this->messages->compose($actingUser, $to, $cc, $bcc, (string) $request->input('subject', ''), (string) $request->input('body', ''), $linkableType, $linkableId, $files);
        } catch (ValidationException $e) {
            return $this->composeForm(null, $e->errors, $request->all(), [$linkableType, $linkableId]);
        }

        Session::setFlash('success', t('mail.flash.sent'));

        $this->maybeNotify($actingUser, array_merge($to, $cc, $bcc), (int) $message['id'], $request->input('notify') === '1');

        return $this->redirect('/mail/thread/' . $message['id']);
    }

    public function showEditDraft(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $draft = $this->messages->findDraft(CurrentUser::user(), $id);
        $recipients = json_decode((string) ($draft['draft_recipients_json'] ?? ''), true) ?: ['to' => [], 'cc' => [], 'bcc' => []];

        return $this->composeForm($draft, [], [
            'subject' => $draft['subject'],
            'body' => $draft['body'],
            'to' => implode(',', $recipients['to'] ?? []),
            'cc' => implode(',', $recipients['cc'] ?? []),
            'bcc' => implode(',', $recipients['bcc'] ?? []),
        ], [$draft['linkable_type'], $draft['linkable_id'] !== null ? (int) $draft['linkable_id'] : null], $this->messages->attachmentsFor($id));
    }

    public function updateDraft(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $actingUser = CurrentUser::user();
        [$to, $cc, $bcc] = $this->parseRecipients($request);
        $files = $request->files('attachments');

        $existing = $this->messages->findDraft($actingUser, $id);
        $linkableType = $existing['linkable_type'];
        $linkableId = $existing['linkable_id'] !== null ? (int) $existing['linkable_id'] : null;

        if ((string) $request->input('intent', 'save_draft') === 'send') {
            try {
                $this->messages->saveDraft($actingUser, $id, $to, $cc, $bcc, (string) $request->input('subject', ''), (string) $request->input('body', ''), $linkableType, $linkableId, $files);
                $message = $this->messages->sendDraft($actingUser, $id);
            } catch (ValidationException $e) {
                return $this->composeForm($this->messages->findDraft($actingUser, $id), $e->errors, $request->all());
            }

            Session::setFlash('success', t('mail.flash.sent'));
            $this->maybeNotify($actingUser, array_merge($to, $cc, $bcc), (int) $message['id'], $request->input('notify') === '1');

            return $this->redirect('/mail/thread/' . $message['id']);
        }

        try {
            $this->messages->saveDraft($actingUser, $id, $to, $cc, $bcc, (string) $request->input('subject', ''), (string) $request->input('body', ''), $linkableType, $linkableId, $files);
        } catch (ValidationException $e) {
            return $this->composeForm($this->messages->findDraft($actingUser, $id), $e->errors, $request->all());
        }
        Session::setFlash('success', t('mail.flash.draft_saved'));

        return $this->redirect('/mail/drafts/' . $id . '/edit');
    }

    public function deleteAttachment(Request $request): Response
    {
        $this->messages->removeAttachment(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('mail.flash.attachment_removed'));

        return $this->redirect($request->input('return_to', '/mail/drafts'));
    }

    public function downloadAttachment(Request $request): Response
    {
        $attachment = $this->messages->requireAttachmentAccess(CurrentUser::user(), (int) $request->attribute('id'));
        $path = $this->messages->absoluteAttachmentPath($attachment);

        if (!is_file($path)) {
            throw new NotFoundException();
        }

        return Response::html((string) file_get_contents($path))
            ->withHeader('Content-Type', $attachment['mime_type'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . str_replace('"', '', $attachment['original_filename']) . '"');
    }

    public function deleteDraft(Request $request): Response
    {
        $this->messages->deleteDraft(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('mail.flash.draft_deleted'));

        return $this->redirect('/mail/drafts');
    }

    public function thread(Request $request): Response
    {
        $rootId = (int) $request->attribute('id');
        $thread = $this->messages->thread(CurrentUser::user(), $rootId);

        $attachmentsByMessage = [];
        foreach ($thread['messages'] as $msg) {
            $attachmentsByMessage[$msg['id']] = $this->messages->attachmentsFor((int) $msg['id']);
        }

        return $this->viewWithLayout('mail/thread', 'layout', [
            'rootId' => $rootId,
            'messages' => $thread['messages'],
            'recipientRows' => $thread['recipientRows'],
            'attachmentsByMessage' => $attachmentsByMessage,
        ]);
    }

    public function reply(Request $request): Response
    {
        $rootId = (int) $request->attribute('id');

        try {
            $result = $this->messages->reply(CurrentUser::user(), $rootId, (string) $request->input('body', ''), $request->files('attachments'));
            $this->maybeNotify(CurrentUser::user(), $result['recipientIds'], (int) $result['message']['id'], $request->input('notify') === '1');
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'mail.error.reply_failed')));
        }

        return $this->redirect('/mail/thread/' . $rootId);
    }

    /** Compose form prefilled with "Fwd:" subject, the quoted original and its attachments (all ticked). */
    public function showForward(Request $request): Response
    {
        $source = $this->messages->forwardSource(CurrentUser::user(), (int) $request->attribute('id'));
        $original = $source['message'];

        $old = [
            'subject' => 'Fwd: ' . preg_replace('/^(Fwd: )+/', '', (string) $original['subject']),
            'body' => "\n\n---------- " . t('mail.forwarded_message') . " ----------\n"
                . t('mail.from') . ': ' . $source['sender_name'] . "\n"
                . t('mail.forward_date') . ': ' . $original['created_at'] . "\n"
                . t('mail.subject') . ': ' . $original['subject'] . "\n\n"
                . $original['body'],
            'forward_attachments' => array_column($source['attachments'], 'id'),
        ];

        return $this->composeForm(null, [], $old, [null, null], [], $source);
    }

    public function forward(Request $request): Response
    {
        $actingUser = CurrentUser::user();
        $messageId = (int) $request->attribute('id');
        [$to, $cc, $bcc] = $this->parseRecipients($request);
        $attachmentIds = array_map('intval', (array) $request->input('forward_attachments', []));

        try {
            $message = $this->messages->forward($actingUser, $messageId, $to, $cc, $bcc, (string) $request->input('subject', ''), (string) $request->input('body', ''), $attachmentIds, $request->files('attachments'));
        } catch (ValidationException $e) {
            $old = $request->all();
            $old['forward_attachments'] = $attachmentIds;

            return $this->composeForm(null, $e->errors, $old, [null, null], [], $this->messages->forwardSource($actingUser, $messageId));
        }

        Session::setFlash('success', t('mail.forward_sent'));
        $this->maybeNotify($actingUser, array_merge($to, $cc, $bcc), (int) $message['id'], $request->input('notify') === '1');

        return $this->redirect('/mail/thread/' . $message['id']);
    }

    public function deleteFromInbox(Request $request): Response
    {
        $this->messages->deleteFromInbox(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('mail.flash.trashed'));

        return $this->redirect('/mail');
    }

    public function restoreToInbox(Request $request): Response
    {
        $this->messages->restoreToInbox(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('mail.flash.restored'));

        return $this->redirect('/mail/trash');
    }

    public function deleteFromSent(Request $request): Response
    {
        $this->messages->deleteFromSent(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('mail.flash.trashed'));

        return $this->redirect('/mail/sent');
    }

    /** Bulk "move to trash" from the inbox/sent/drafts list; returns to the same page. */
    public function bulkTrash(Request $request, string $folder): Response
    {
        $home = ['inbox' => '/mail', 'sent' => '/mail/sent', 'drafts' => '/mail/drafts'][$folder];

        $result = $this->messages->bulkTrash(CurrentUser::user(), $folder, (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('mail.bulk_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', $home);
        if (!str_starts_with($back, '/mail') || str_contains($back, '//')) {
            $back = $home;
        }

        return $this->redirect($back);
    }

    public function restoreToSent(Request $request): Response
    {
        $this->messages->restoreToSent(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('mail.flash.restored'));

        return $this->redirect('/mail/trash');
    }

    /** @return array{0: int[], 1: int[], 2: int[]} [to, cc, bcc] */
    private function parseRecipients(Request $request): array
    {
        $parse = fn ($raw) => array_values(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw))));

        return [
            $parse($request->input('to', $request->input('recipients', []))),
            $parse($request->input('cc', '')),
            $parse($request->input('bcc', '')),
        ];
    }

    private const LINKABLE_TYPES = ['contact', 'company', 'lead', 'deal', 'ticket', 'kb_article'];

    /** @return array{0: ?string, 1: ?int} [linkable_type, linkable_id] — from a GET link like /mail/compose?linkable_type=contact&linkable_id=5, for a future "Send Email" button on that record's own page. */
    private function linkableFromQuery(Request $request): array
    {
        return $this->resolveLinkable((string) $request->query('linkable_type', ''), (string) $request->query('linkable_id', ''));
    }

    private function linkableFromInput(Request $request): array
    {
        return $this->resolveLinkable((string) $request->input('linkable_type', ''), (string) $request->input('linkable_id', ''));
    }

    private function resolveLinkable(string $type, string $id): array
    {
        if ($type === '' || $id === '' || !in_array($type, self::LINKABLE_TYPES, true)) {
            return [null, null];
        }

        return [$type, (int) $id];
    }

    private function composeForm(?array $draft, array $errors, array $old, array $linkable = [null, null], array $attachments = [], ?array $forward = null): Response
    {
        $actingUser = CurrentUser::user();

        return $this->viewWithLayout('mail/compose', 'layout', [
            'draft' => $draft,
            'colleagues' => array_filter(
                $this->users->findAllInOrganization((int) $actingUser['organization_id']),
                fn ($u) => (int) $u['id'] !== (int) $actingUser['id']
            ),
            'errors' => $errors,
            'old' => $old,
            'linkableType' => $draft['linkable_type'] ?? $linkable[0],
            'linkableId' => $draft['linkable_id'] ?? $linkable[1],
            'attachments' => $attachments,
            'forward' => $forward,
        ], $errors === [] ? 200 : 422);
    }

    /**
     * Resolved lazily via the container, at request-time — Notification
     * may not be installed at all (the container simply won't have this
     * binding), or it may be installed but not enabled for this org (the
     * dispatch service handles that gracefully itself, per-recipient,
     * by finding no notification_recipients row and logging
     * skipped_no_destination — it doesn't need to know about
     * ModuleMiddleware or entitlement at all). Either way, Internal Mail
     * itself never breaks or even notices the difference.
     *
     * @param int[] $recipientUserIds
     */
    private function maybeNotify(array $actingUser, array $recipientUserIds, int $messageId, bool $notify): void
    {
        if (!$notify || $recipientUserIds === [] || !$this->container->has(NotificationDispatchService::class)) {
            return;
        }

        $dispatch = $this->container->make(NotificationDispatchService::class);
        $appName = (string) Config::get('app.name', 'CRM');

        $dispatch->notifyNewMessage((int) $actingUser['organization_id'], $recipientUserIds, $messageId, $appName);
    }
}
