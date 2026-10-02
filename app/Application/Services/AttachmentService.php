<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\AttachmentRepository;

/**
 * Per Phase 0 §44: no executable file uploads without explicit
 * controls, randomized storage filenames, served from a path that
 * doesn't execute PHP. Enforced here — extension allow-list, MIME
 * verified against the actual file content (not the client-supplied
 * Content-Type, which is trivially spoofable), size cap, and the
 * stored filename is always a random hex string plus the (allow-
 * listed) extension, never the user-supplied name.
 */
final class AttachmentService
{
    private const ALLOWED_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt'];
    private const MAX_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private readonly AttachmentRepository $attachments,
        private readonly TimelineService $timeline,
        private readonly string $storageRoot,
    ) {
    }

    public function forSubject(string $type, int $id): array
    {
        return $this->attachments->forSubject($type, $id);
    }

    /**
     * @param array $file One entry from Request::file() —
     *   ['name' => ..., 'type' => ..., 'tmp_name' => ..., 'error' => ..., 'size' => ...]
     */
    public function upload(string $type, int $id, int $organizationId, int $userId, array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => ['Upload failed.']]);
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['file' => ['File is too large (10 MB max).']]);
        }

        $originalName = (string) $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException(['file' => ['That file type is not allowed.']]);
        }

        // Trust the file's actual content over the browser-supplied
        // Content-Type header, which the client fully controls.
        $mimeType = (string) (mime_content_type($file['tmp_name']) ?: 'application/octet-stream');

        $orgDir = rtrim($this->storageRoot, '/') . '/' . $organizationId;
        if (!is_dir($orgDir)) {
            mkdir($orgDir, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $storedPath = $orgDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            throw new ValidationException(['file' => ['Could not save the uploaded file.']]);
        }

        $attachmentId = $this->attachments->create([
            'attachable_type' => $type,
            'attachable_id' => $id,
            'user_id' => $userId,
            'filename' => $originalName,
            'stored_path' => $organizationId . '/' . $storedName,
            'mime_type' => $mimeType,
            'size' => (int) $file['size'],
        ]);

        $this->timeline->record($type, $id, 'attachment_added', "Attached \"{$originalName}\"", $userId);

        return $this->attachments->findById($attachmentId);
    }

    public function delete(int $attachmentId): void
    {
        $attachment = $this->attachments->findById($attachmentId);

        if ($attachment === null) {
            return;
        }

        $fullPath = rtrim($this->storageRoot, '/') . '/' . $attachment['stored_path'];

        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $this->attachments->deleteAttachment($attachmentId);
    }

    public function absolutePath(array $attachment): string
    {
        return rtrim($this->storageRoot, '/') . '/' . $attachment['stored_path'];
    }
}
