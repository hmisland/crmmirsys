<?php

declare(strict_types=1);

namespace Modules\InternalMail\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\UserRepository;
use Modules\InternalMail\MessageAttachmentRepository;
use Modules\InternalMail\MessageRecipientRepository;
use Modules\InternalMail\MessageRepository;

final class MessageService
{
    private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024; // 25 MB, same cap as File Library

    public function __construct(
        private readonly MessageRepository $messages,
        private readonly MessageRecipientRepository $recipients,
        private readonly MessageAttachmentRepository $attachments,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly string $storageRoot,
    ) {
    }

    public function inbox(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::MAIL_VIEW);

        return $this->recipients->inboxForUser((int) $actingUser['id']);
    }

    public function sent(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::MAIL_VIEW);

        return $this->messages->sentByUser((int) $actingUser['id']);
    }

    public function drafts(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::MAIL_VIEW);

        return $this->messages->draftsByUser((int) $actingUser['id']);
    }

    /** Both the recipient-side trash (things trashed from Inbox) and the sender-side trash (things trashed from Sent/Drafts) — shown together as one Trash folder, same as any ordinary mail client. */
    public function trash(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::MAIL_VIEW);

        $userId = (int) $actingUser['id'];

        return [
            'inbox' => $this->recipients->trashForUser($userId),
            'sent' => $this->messages->senderTrash($userId),
        ];
    }

    public function unreadCount(array $actingUser): int
    {
        return $this->recipients->unreadCountForUser((int) $actingUser['id']);
    }

    public function findDraft(array $actingUser, int $id): array
    {
        $draft = $this->requireOwnDraft($actingUser, $id);

        return $draft;
    }

    /** @return array{messages: array, recipientRows: array} the root message plus every reply, and who received the root (Bcc filtered per-viewer). */
    public function thread(array $actingUser, int $rootId): array
    {
        $this->authorize($actingUser, Permission::MAIL_VIEW);

        $messages = $this->messages->forThread($rootId);
        if ($messages === []) {
            throw new NotFoundException('Message not found.');
        }

        $root = $messages[0];
        $userId = (int) $actingUser['id'];
        $isSender = (int) $root['sender_user_id'] === $userId;

        $this->requireParticipant($actingUser, $rootId);
        $this->recipients->markRead($rootId, $userId);

        return ['messages' => $messages, 'recipientRows' => $this->recipients->visibleRecipientsFor($rootId, $userId, $isSender)];
    }

    /**
     * @param int[] $to
     * @param int[] $cc
     * @param int[] $bcc
     */
    /** @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $files From Request::files('attachments'). */
    public function compose(array $actingUser, array $to, array $cc, array $bcc, string $subject, string $body, ?string $linkableType = null, ?int $linkableId = null, array $files = []): array
    {
        $this->authorize($actingUser, Permission::MAIL_SEND);

        return $this->send($actingUser, null, $to, $cc, $bcc, $subject, $body, $linkableType, $linkableId, null, $files);
    }

    /** @param int[] $to @param int[] $cc @param int[] $bcc @param array $files From Request::files('attachments') — appended to whatever's already attached to this draft. */
    public function saveDraft(array $actingUser, ?int $draftId, array $to, array $cc, array $bcc, string $subject, string $body, ?string $linkableType = null, ?int $linkableId = null, array $files = []): array
    {
        $this->authorize($actingUser, Permission::MAIL_SEND);

        $fields = [
            'sender_user_id' => $actingUser['id'],
            'thread_id' => null,
            'status' => 'draft',
            'subject' => trim($subject),
            'body' => trim($body),
            'draft_recipients_json' => json_encode(['to' => $to, 'cc' => $cc, 'bcc' => $bcc]),
            'linkable_type' => $linkableType,
            'linkable_id' => $linkableId,
        ];

        if ($draftId === null) {
            $id = $this->messages->create($fields);
        } else {
            $this->requireOwnDraft($actingUser, $draftId);
            unset($fields['sender_user_id'], $fields['thread_id'], $fields['status']);
            $this->messages->updateMessage($draftId, $fields);
            $id = $draftId;
        }

        $this->storeAttachments($actingUser, $id, $files);

        return $this->messages->findById($id);
    }

    public function sendDraft(array $actingUser, int $draftId): array
    {
        $draft = $this->requireOwnDraft($actingUser, $draftId);

        $recipients = json_decode((string) ($draft['draft_recipients_json'] ?? ''), true) ?: ['to' => [], 'cc' => [], 'bcc' => []];

        $message = $this->send(
            $actingUser,
            null,
            array_map('intval', $recipients['to'] ?? []),
            array_map('intval', $recipients['cc'] ?? []),
            array_map('intval', $recipients['bcc'] ?? []),
            (string) $draft['subject'],
            (string) $draft['body'],
            $draft['linkable_type'],
            $draft['linkable_id'] !== null ? (int) $draft['linkable_id'] : null,
            $draftId
        );

        return $message;
    }

    public function deleteDraft(array $actingUser, int $id): void
    {
        $this->requireOwnDraft($actingUser, $id);
        $this->messages->softDeleteBySender($id);
    }

    /** @param array $files From Request::files('attachments'). */
    public function reply(array $actingUser, int $rootId, string $body, array $files = []): array
    {
        $this->authorize($actingUser, Permission::MAIL_SEND);
        $this->requireParticipant($actingUser, $rootId);

        $root = $this->messages->findById($rootId);
        if ($root === null) {
            throw new NotFoundException('Message not found.');
        }

        // Reply goes to everyone who was on the root message (To and
        // Cc — never silently re-adding a Bcc'd person's identity
        // into a visible reply-all the way the original Bcc sender
        // did not intend), minus the person replying.
        $recipientIds = array_filter(
            array_column(
                array_filter($this->recipients->forMessage($rootId), fn (array $r) => $r['recipient_type'] !== 'bcc'),
                'recipient_user_id'
            ),
            fn ($id) => (int) $id !== (int) $actingUser['id']
        );
        if ((int) $root['sender_user_id'] !== (int) $actingUser['id']) {
            $recipientIds[] = (int) $root['sender_user_id'];
        }
        $recipientIds = array_values(array_unique(array_map('intval', $recipientIds)));

        $message = $this->send($actingUser, $rootId, $recipientIds, [], [], 'Re: ' . preg_replace('/^Re: /', '', $root['subject']), $body, $root['linkable_type'], $root['linkable_id'] !== null ? (int) $root['linkable_id'] : null, null, $files);

        return ['message' => $message, 'recipientIds' => $recipientIds];
    }

    /**
     * @param int[] $to @param int[] $cc @param int[] $bcc
     * $reuseMessageId: when sending a draft, write into that same
     * row (so its id/thread position doesn't change) instead of
     * creating a new one.
     */
    private function send(array $actingUser, ?int $threadId, array $to, array $cc, array $bcc, string $subject, string $body, ?string $linkableType, ?int $linkableId, ?int $reuseMessageId = null, array $files = []): array
    {
        if (trim($subject) === '') {
            throw new ValidationException(['subject' => ['Subject is required.']]);
        }
        if (trim($body) === '') {
            throw new ValidationException(['body' => ['Message cannot be empty.']]);
        }

        $organizationId = (int) $actingUser['organization_id'];
        $validate = function (array $ids) use ($organizationId): array {
            $valid = [];
            foreach (array_unique($ids) as $id) {
                $user = $this->users->findById((int) $id);
                if ($user !== null && (int) ($user['organization_id'] ?? 0) === $organizationId) {
                    $valid[] = (int) $id;
                }
            }

            return $valid;
        };

        $validTo = $validate($to);
        $validCc = $validate($cc);
        $validBcc = $validate($bcc);

        if ($validTo === [] && $validCc === [] && $validBcc === []) {
            throw new ValidationException(['recipients' => ['Choose at least one recipient in your organization.']]);
        }

        $fields = [
            'sender_user_id' => $actingUser['id'],
            'thread_id' => $threadId,
            'status' => 'sent',
            'subject' => trim($subject),
            'body' => trim($body),
            'draft_recipients_json' => null,
            'linkable_type' => $linkableType,
            'linkable_id' => $linkableId,
        ];

        if ($reuseMessageId === null) {
            $messageId = $this->messages->create($fields);
        } else {
            unset($fields['sender_user_id'], $fields['thread_id']);
            $this->messages->updateMessage($reuseMessageId, $fields);
            $messageId = $reuseMessageId;
        }

        foreach ($validTo as $id) {
            $this->recipients->create($messageId, $id, 'to');
        }
        foreach ($validCc as $id) {
            $this->recipients->create($messageId, $id, 'cc');
        }
        foreach ($validBcc as $id) {
            $this->recipients->create($messageId, $id, 'bcc');
        }

        if ($linkableType !== null && $linkableId !== null) {
            $this->timeline->record($linkableType, $linkableId, 'mail_sent', 'Email: "' . trim($subject) . '"', (int) $actingUser['id']);
        }

        $this->storeAttachments($actingUser, $messageId, $files);

        return $this->messages->findById($messageId);
    }

    public function attachmentsFor(int $messageId): array
    {
        return $this->attachments->forMessage($messageId);
    }

    /** Downloading requires being a participant (sender or any recipient, To/Cc/Bcc alike) of the attachment's own message — never just "logged in". */
    public function requireAttachmentAccess(array $actingUser, int $attachmentId): array
    {
        $attachment = $this->attachments->findById($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('Attachment not found.');
        }

        $this->requireParticipant($actingUser, $this->rootIdFor((int) $attachment['message_id']));

        return $attachment;
    }

    /** Only while the message is still the sender's own draft — once sent, attachments are part of a delivered message and can't be edited away. */
    public function removeAttachment(array $actingUser, int $attachmentId): void
    {
        $attachment = $this->attachments->findById($attachmentId);
        if ($attachment === null) {
            throw new NotFoundException('Attachment not found.');
        }

        $this->requireOwnDraft($actingUser, (int) $attachment['message_id']);
        $this->deleteStoredFile($attachment);
        $this->attachments->deleteAttachment($attachmentId);
    }

    public function absoluteAttachmentPath(array $attachment): string
    {
        return rtrim($this->storageRoot, '/') . '/' . $attachment['filename'];
    }

    /**
     * @param array<int, array{name: string, tmp_name: string, error: int, size: int}> $files
     * Same storage architecture as File Library: outside the
     * webroot, random hex filename, organization-scoped subdirectory
     * — safe to accept any file type because it's never served back
     * except through a controlled download response.
     */
    private function storeAttachments(array $actingUser, int $messageId, array $files): void
    {
        if ($files === []) {
            return;
        }

        $organizationId = (int) $actingUser['organization_id'];
        $orgDir = rtrim($this->storageRoot, '/') . '/' . $organizationId;
        if (!is_dir($orgDir)) {
            mkdir($orgDir, 0755, true);
        }

        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            if ((int) $file['size'] > self::MAX_ATTACHMENT_BYTES) {
                throw new ValidationException(['attachments' => ['Each attachment must be 25 MB or smaller.']]);
            }

            $originalName = (string) $file['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $mimeType = (string) (mime_content_type($file['tmp_name']) ?: 'application/octet-stream');

            $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
            $storedPath = $orgDir . '/' . $storedName;

            if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                throw new ValidationException(['attachments' => ['Could not save one of the attached files.']]);
            }

            $this->attachments->create([
                'message_id' => $messageId,
                'filename' => $organizationId . '/' . $storedName,
                'original_filename' => $originalName,
                'mime_type' => $mimeType,
                'size' => (int) $file['size'],
                'uploaded_by_user_id' => $actingUser['id'],
            ]);
        }
    }

    private function deleteStoredFile(array $attachment): void
    {
        $path = $this->absoluteAttachmentPath($attachment);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function deleteFromInbox(array $actingUser, int $messageId): void
    {
        $this->requireParticipant($actingUser, $this->rootIdFor($messageId));
        $this->recipients->softDelete($messageId, (int) $actingUser['id']);
    }

    public function restoreToInbox(array $actingUser, int $messageId): void
    {
        $this->requireParticipant($actingUser, $this->rootIdFor($messageId));
        $this->recipients->restore($messageId, (int) $actingUser['id']);
    }

    public function deleteFromSent(array $actingUser, int $messageId): void
    {
        $message = $this->messages->findById($messageId);
        if ($message === null || (int) $message['sender_user_id'] !== (int) $actingUser['id']) {
            throw new AuthorizationException();
        }
        $this->messages->softDeleteBySender($messageId);
    }

    public function restoreToSent(array $actingUser, int $messageId): void
    {
        $message = $this->messages->findById($messageId);
        if ($message === null || (int) $message['sender_user_id'] !== (int) $actingUser['id']) {
            throw new AuthorizationException();
        }
        $this->messages->restoreBySender($messageId);
    }

    private function rootIdFor(int $messageId): int
    {
        $message = $this->messages->findById($messageId);

        return $message !== null && $message['thread_id'] !== null ? (int) $message['thread_id'] : $messageId;
    }

    private function requireOwnDraft(array $actingUser, int $id): array
    {
        $draft = $this->messages->findById($id);

        if ($draft === null || $draft['status'] !== 'draft' || (int) $draft['sender_user_id'] !== (int) $actingUser['id']) {
            throw new NotFoundException('Draft not found.');
        }

        return $draft;
    }

    private function requireParticipant(array $actingUser, int $rootId): void
    {
        $userId = (int) $actingUser['id'];
        $root = $this->messages->findById($rootId);

        if ($root !== null && (int) $root['sender_user_id'] === $userId) {
            return;
        }

        foreach ($this->messages->forThread($rootId) as $msg) {
            if ((int) $msg['sender_user_id'] === $userId) {
                return;
            }
            foreach ($this->recipients->forMessage((int) $msg['id']) as $row) {
                if ((int) $row['recipient_user_id'] === $userId) {
                    return;
                }
            }
        }

        throw new AuthorizationException();
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
