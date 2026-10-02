<?php

declare(strict_types=1);

namespace Modules\Knowledge\Services;

use App\Application\Services\TagService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Knowledge\FileLibraryDocumentRepository;
use Modules\Knowledge\FileLibraryUploaderRepository;

/**
 * "Any file type" is genuinely safe to accept here (per the explicit
 * requirement — a scanned license, an ID photo, anything) precisely
 * BECAUSE storage mirrors AttachmentService's own architecture: files
 * live outside the public webroot under a random hex filename and are
 * only ever served back out through downloadDocument()'s own
 * controlled Content-Disposition: attachment response — never
 * requested directly by path, so even an uploaded .php or .exe file
 * is inert bytes on disk, never executed. Dropping the extension
 * allow-list is safe only because that storage architecture is kept
 * intact; it would NOT be safe if files were ever moved into a
 * directly web-servable directory.
 */
final class FileLibraryService
{
    private const TAGGABLE_TYPE = 'file_library_document';
    private const MAX_BYTES = 25 * 1024 * 1024; // 25 MB — company documents/scans run bigger than typical CRM attachments

    public function __construct(
        private readonly FileLibraryDocumentRepository $documents,
        private readonly FileLibraryUploaderRepository $uploaders,
        private readonly TagService $tags,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
        private readonly string $storageRoot,
    ) {
    }

    public function list(array $actingUser, ?string $tagSearch = null): array
    {
        $this->authorize($actingUser, Permission::FILES_VIEW);

        if ($tagSearch !== null && trim($tagSearch) !== '') {
            $ids = $this->tags->subjectIdsWithTagName(self::TAGGABLE_TYPE, trim($tagSearch));
            $documents = $this->documents->findByIds($ids);
        } else {
            $documents = $this->documents->all();
        }

        foreach ($documents as &$doc) {
            $doc['tags'] = $this->tags->forSubject(self::TAGGABLE_TYPE, (int) $doc['id']);
        }
        unset($doc);

        return $documents;
    }

    /** @param array $file One entry from Request::file(). @param string[] $tagNames */
    public function upload(array $actingUser, array $file, ?string $description, array $tagNames): array
    {
        $this->requireCanManage($actingUser);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => ['Upload failed.']]);
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['file' => ['File is too large (25 MB max).']]);
        }

        $originalName = (string) $file['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mimeType = (string) (mime_content_type($file['tmp_name']) ?: 'application/octet-stream');

        $organizationId = (int) $actingUser['organization_id'];
        $orgDir = rtrim($this->storageRoot, '/') . '/' . $organizationId;
        if (!is_dir($orgDir)) {
            mkdir($orgDir, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $storedPath = $orgDir . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
            throw new ValidationException(['file' => ['Could not save the uploaded file.']]);
        }

        $documentId = $this->documents->create([
            'filename' => $organizationId . '/' . $storedName,
            'original_filename' => $originalName,
            'mime_type' => $mimeType,
            'size' => (int) $file['size'],
            'description' => $description !== null && trim($description) !== '' ? trim($description) : null,
            'uploaded_by_user_id' => (int) $actingUser['id'],
        ]);

        foreach ($tagNames as $tagName) {
            if (trim((string) $tagName) !== '') {
                $this->tags->attach(self::TAGGABLE_TYPE, $documentId, (string) $tagName, (int) $actingUser['id']);
            }
        }

        return $this->documents->findById($documentId);
    }

    public function delete(array $actingUser, int $id): void
    {
        $this->requireCanManage($actingUser);

        $document = $this->documents->findById($id);
        if ($document === null) {
            throw new NotFoundException('File not found.');
        }

        $fullPath = rtrim($this->storageRoot, '/') . '/' . $document['filename'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        $this->documents->deleteDocument($id);
    }

    public function absolutePath(array $document): string
    {
        return rtrim($this->storageRoot, '/') . '/' . $document['filename'];
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::FILES_VIEW);

        $document = $this->documents->findById($id);
        if ($document === null) {
            throw new NotFoundException('File not found.');
        }

        return $document;
    }

    /** A plain yes/no version of requireCanManage() for UI decisions (show/hide the upload form) rather than gating an action. */
    public function canManage(array $actingUser): bool
    {
        if ($this->authorization->can($actingUser, Permission::FILES_MANAGE)) {
            return true;
        }

        $organizationId = (int) ($actingUser['organization_id'] ?? 0);

        return $organizationId > 0 && $this->uploaders->isUploader($organizationId, (int) $actingUser['id']);
    }

    // --- Uploader allow-list (org_admin only) -----------------------

    public function uploaderCandidates(array $actingUser): array
    {
        $this->requireManageUploaders($actingUser);

        $organizationId = (int) $actingUser['organization_id'];
        $granted = array_column($this->uploaders->forOrganization(), 'user_id');

        return [
            'all' => $this->users->findAllInOrganization($organizationId),
            'grantedUserIds' => array_map('intval', $granted),
        ];
    }

    public function grantUploader(array $actingUser, int $userId): void
    {
        $this->requireManageUploaders($actingUser);

        $this->uploaders->grant((int) $actingUser['organization_id'], $userId, (int) $actingUser['id']);
    }

    public function revokeUploader(array $actingUser, int $userId): void
    {
        $this->requireManageUploaders($actingUser);

        $this->uploaders->revoke((int) $actingUser['organization_id'], $userId);
    }

    /**
     * org_admin always can; a member additionally can if org_admin
     * put them on the allow-list — the two-layer check the explicit
     * requirement described ("access is determined and managed by
     * org admin," for some or all users).
     */
    private function requireCanManage(array $actingUser): void
    {
        if (!$this->canManage($actingUser)) {
            throw new AuthorizationException();
        }
    }

    private function requireManageUploaders(array $actingUser): void
    {
        if (!$this->authorization->can($actingUser, Permission::FILES_MANAGE_UPLOADERS)) {
            throw new AuthorizationException();
        }
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
