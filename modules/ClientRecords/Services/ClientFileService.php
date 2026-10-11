<?php

declare(strict_types=1);

namespace Modules\ClientRecords\Services;

use App\Application\Services\AttachmentService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\Permission;
use Modules\ClientRecords\ClientFileRepository;
use Modules\ClientRecords\ServiceRecordRepository;
use Modules\ClientRecords\SessionRepository;

/**
 * Before/after media and documents of a client record. Storage is the
 * Core AttachmentService (allow-listed extensions, real MIME check,
 * random filename, outside the web root); this service only adds the
 * clinical-history metadata and the stricter access rules:
 *
 *  - viewing/downloading any file requires client_records.view_sensitive
 *  - uploading/deleting requires manage_media / manage_documents
 *  - media must be an image
 *
 * Attachments are stored under their own attachable types, which the
 * generic /records/{type}/... controller does not accept, so these
 * files are reachable only through this module's checked route.
 */
final class ClientFileService
{
    public const MEDIA_CATEGORIES = ['before', 'after', 'progress', 'other'];
    public const DOCUMENT_CATEGORIES = ['consent', 'identity', 'client_provided', 'treatment', 'other'];
    public const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    private const ATTACHABLE_TYPE = ['media' => 'client_record_media', 'document' => 'client_record_doc'];
    private const MANAGE_PERMISSION = [
        'media' => Permission::CLIENT_RECORDS_MANAGE_MEDIA,
        'document' => Permission::CLIENT_RECORDS_MANAGE_DOCUMENTS,
    ];

    public function __construct(
        private readonly ClientFileRepository $files,
        private readonly ServiceRecordRepository $services,
        private readonly SessionRepository $sessions,
        private readonly AttachmentService $attachments,
        private readonly ClientRecordService $records,
        private readonly TimelineService $timeline,
    ) {
    }

    /** Files of one kind with their attachment info merged in; empty when the caller may not view them. */
    public function filesFor(array $actingUser, array $record, string $kind): array
    {
        if (!$this->records->canViewSensitive($actingUser, $record)) {
            return [];
        }

        $attachmentsById = [];
        foreach ($this->attachments->forSubject(self::ATTACHABLE_TYPE[$kind], (int) $record['id']) as $attachment) {
            $attachmentsById[(int) $attachment['id']] = $attachment;
        }

        $rows = [];
        foreach ($this->files->forRecord((int) $record['id'], $kind) as $file) {
            $attachment = $attachmentsById[(int) $file['attachment_id']] ?? null;
            if ($attachment === null) {
                continue;
            }

            $rows[] = $file + [
                'filename' => $attachment['filename'],
                'mime_type' => $attachment['mime_type'],
                'size' => (int) $attachment['size'],
                'is_image' => str_starts_with((string) $attachment['mime_type'], 'image/'),
            ];
        }

        return $rows;
    }

    public function upload(array $actingUser, array $record, string $kind, ?array $file, array $input): array
    {
        $this->assertKind($kind);
        $this->records->authorizeRecord($actingUser, self::MANAGE_PERMISSION[$kind], $record);

        if ($file === null) {
            throw new ValidationException(['file' => ['client_records.validation.file_required']]);
        }

        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if ($kind === 'media' && !in_array($extension, self::IMAGE_EXTENSIONS, true)) {
            throw new ValidationException(['file' => ['client_records.validation.image_only']]);
        }

        $categories = $kind === 'media' ? self::MEDIA_CATEGORIES : self::DOCUMENT_CATEGORIES;
        $category = (string) ($input['category'] ?? 'other');
        if (!in_array($category, $categories, true)) {
            throw new ValidationException(['category' => ['client_records.validation.invalid_category']]);
        }

        $serviceId = $this->ownedChildId($this->services->findById(...), $record, $input['service_record_id'] ?? null, 'service_record_id');
        $sessionId = $this->ownedChildId($this->sessions->findById(...), $record, $input['session_id'] ?? null, 'session_id');

        $takenAt = trim((string) ($input['taken_at'] ?? ''));
        if ($takenAt !== '' && \DateTimeImmutable::createFromFormat('!Y-m-d', $takenAt)?->format('Y-m-d') !== $takenAt) {
            throw new ValidationException(['taken_at' => ['client_records.validation.invalid_date']]);
        }

        // Core does extension allow-list, size cap, MIME sniffing, storage.
        try {
            $attachment = $this->attachments->upload(
                self::ATTACHABLE_TYPE[$kind],
                (int) $record['id'],
                (int) $actingUser['organization_id'],
                (int) $actingUser['id'],
                $file
            );
        } catch (ValidationException $e) {
            throw new ValidationException(['file' => [array_values($e->errors)[0][0] ?? 'client_records.validation.file_required']]);
        }

        if ($kind === 'media' && !str_starts_with((string) $attachment['mime_type'], 'image/')) {
            // Extension said image, content says otherwise — don't keep it.
            $this->attachments->delete((int) $attachment['id']);
            throw new ValidationException(['file' => ['client_records.validation.image_only']]);
        }

        $notes = trim((string) ($input['notes'] ?? ''));
        $id = $this->files->create([
            'client_record_id' => (int) $record['id'],
            'attachment_id' => (int) $attachment['id'],
            'kind' => $kind,
            'category' => $category,
            'service_record_id' => $serviceId,
            'session_id' => $sessionId,
            'taken_at' => $takenAt !== '' ? $takenAt : null,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
            'uploaded_by_user_id' => (int) $actingUser['id'],
        ]);

        $this->timeline->record('client_record', (int) $record['id'], $kind . '_uploaded', ucfirst($kind) . ' uploaded (' . $category . ')', (int) $actingUser['id']);

        return $this->files->findById($id);
    }

    public function delete(array $actingUser, array $record, int $fileId): void
    {
        $file = $this->requireFile($record, $fileId);
        $this->records->authorizeRecord($actingUser, self::MANAGE_PERMISSION[$file['kind']], $record);

        // Removes the stored file and the attachments row; the metadata
        // row goes with it (FK ON DELETE CASCADE).
        $this->attachments->delete((int) $file['attachment_id']);
        $this->files->deleteFile($fileId); // no-op if the cascade already removed it

        $this->timeline->record('client_record', (int) $record['id'], $file['kind'] . '_deleted', ucfirst((string) $file['kind']) . ' deleted (' . $file['category'] . ')', (int) $actingUser['id']);
    }

    /**
     * Everything the controller needs to stream one file, after every
     * check: tenant (repository), file belongs to this record, attachment
     * belongs to this record under the right type, and view_sensitive.
     *
     * @return array{file: array, attachment: array, path: string}
     */
    public function forDownload(array $actingUser, array $record, int $fileId): array
    {
        if (!$this->records->canViewSensitive($actingUser, $record)) {
            throw new AuthorizationException();
        }

        $file = $this->requireFile($record, $fileId);

        $attachment = null;
        foreach ($this->attachments->forSubject(self::ATTACHABLE_TYPE[$file['kind']], (int) $record['id']) as $row) {
            if ((int) $row['id'] === (int) $file['attachment_id']) {
                $attachment = $row;
                break;
            }
        }

        if ($attachment === null) {
            throw new NotFoundException();
        }

        $path = $this->attachments->absolutePath($attachment);
        if (!is_file($path)) {
            throw new NotFoundException();
        }

        return ['file' => $file, 'attachment' => $attachment, 'path' => $path];
    }

    private function requireFile(array $record, int $fileId): array
    {
        $file = $this->files->findById($fileId);

        if ($file === null || (int) $file['client_record_id'] !== (int) $record['id']) {
            throw new NotFoundException();
        }

        return $file;
    }

    /** An optional service/session id from input must belong to this same record. */
    private function ownedChildId(callable $finder, array $record, mixed $raw, string $field): ?int
    {
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return null;
        }

        $row = $finder((int) $raw);
        if ($row === null || (int) $row['client_record_id'] !== (int) $record['id']) {
            throw new ValidationException([$field => ['client_records.validation.invalid_link']]);
        }

        return (int) $row['id'];
    }

    private function assertKind(string $kind): void
    {
        if (!isset(self::ATTACHABLE_TYPE[$kind])) {
            throw new NotFoundException();
        }
    }
}
