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
use Modules\Knowledge\FileLibraryFolderRepository;
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
 *
 * Folders, titles, thumbnails and the one-time server inbox pick-up:
 * docs/file-library-folders-thumbnails-design.md. Thumbnails are
 * re-encoded JPEGs made by GD (never the original bytes) and, like
 * the files themselves, only leave through the controller.
 */
final class FileLibraryService
{
    private const TAGGABLE_TYPE = 'file_library_document';
    private const MAX_BYTES = 25 * 1024 * 1024; // 25 MB — company documents/scans run bigger than typical CRM attachments
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    /** Opened in the browser by /files/{id}/view; everything else downloads. */
    public const INLINE_TYPES = [...self::IMAGE_TYPES, 'application/pdf'];
    private const THUMB_MAX = 320;                 // px, longest side — sharp in the grid on 2x screens
    private const THUMB_MAX_PIXELS = 25_000_000;   // larger sources fall back to the type icon (shared-host memory)
    private const FOLDER_NAME_MAX = 190;
    private const INBOX_TIME_BUDGET = 20;          // seconds per pick-up request (shared-host timeout safe)

    public function __construct(
        private readonly FileLibraryDocumentRepository $documents,
        private readonly FileLibraryFolderRepository $folders,
        private readonly FileLibraryUploaderRepository $uploaders,
        private readonly TagService $tags,
        private readonly UserRepository $users,
        private readonly AuthorizationEngine $authorization,
        private readonly string $storageRoot,
    ) {
    }

    /**
     * One folder's contents (NULL = top level), or — with a search
     * term — matching folders and files (title, filename or exact tag)
     * across every folder, each file labelled with its folder path.
     */
    public function browse(array $actingUser, ?int $folderId, string $search = ''): array
    {
        $this->authorize($actingUser, Permission::FILES_VIEW);

        $allFolders = [];
        foreach ($this->folders->all() as $folder) {
            $allFolders[(int) $folder['id']] = $folder;
        }

        if ($folderId !== null && !isset($allFolders[$folderId])) {
            throw new NotFoundException('Folder not found.');
        }

        $search = trim($search);
        if ($search !== '') {
            $byId = [];
            $tagged = $this->documents->findByIds($this->tags->subjectIdsWithTagName(self::TAGGABLE_TYPE, $search));
            foreach ([...$tagged, ...$this->documents->searchByName($search)] as $doc) {
                $byId[(int) $doc['id']] = $doc;
            }
            $documents = array_values($byId);
            usort($documents, fn ($a, $b) => [$b['created_at'], (int) $b['id']] <=> [$a['created_at'], (int) $a['id']]);

            $subfolders = array_values(array_filter($allFolders, fn ($f) => mb_stripos((string) $f['name'], $search) !== false));
            foreach ($subfolders as &$folder) {
                $folder['item_count'] = null;
                $folder['path'] = $this->pathLabel((int) $folder['id'], $allFolders);
            }
            unset($folder);
        } else {
            $documents = $this->documents->inFolder($folderId);
            $subfolders = $this->folders->children($folderId);
        }

        foreach ($documents as &$doc) {
            $doc['tags'] = $this->tags->forSubject(self::TAGGABLE_TYPE, (int) $doc['id']);
            $doc['display_title'] = self::displayTitle($doc);
            $doc['is_image'] = in_array($doc['mime_type'], self::IMAGE_TYPES, true);
            $doc['is_inline'] = in_array($doc['mime_type'], self::INLINE_TYPES, true);
            $doc['folder_path'] = $doc['folder_id'] !== null ? $this->pathLabel((int) $doc['folder_id'], $allFolders) : '';
        }
        unset($doc);

        $options = [];
        foreach ($allFolders as $id => $folder) {
            $options[] = ['id' => $id, 'label' => $this->pathLabel($id, $allFolders)];
        }
        usort($options, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return [
            'folder' => $folderId !== null ? $allFolders[$folderId] : null,
            'breadcrumb' => $folderId !== null ? $this->ancestry($folderId, $allFolders) : [],
            'folders' => $subfolders,
            'documents' => $documents,
            'folderOptions' => $options,
        ];
    }

    /** Stored title, else the original filename without its extension. */
    public static function displayTitle(array $document): string
    {
        $title = trim((string) ($document['title'] ?? ''));

        return $title !== '' ? $title : self::titleFromFilename((string) $document['original_filename']);
    }

    public static function titleFromFilename(string $filename): string
    {
        $title = trim(pathinfo($filename, PATHINFO_FILENAME));

        return $title !== '' ? $title : $filename;
    }

    /** @param array $file One entry from Request::file(). @param string[] $tagNames */
    public function upload(array $actingUser, array $file, ?string $description, array $tagNames, ?int $folderId = null, ?string $title = null): array
    {
        $this->requireCanManage($actingUser);
        $this->requireFolder($folderId);

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['file' => ['files.validation.upload_failed']]);
        }

        if ((int) $file['size'] > self::MAX_BYTES) {
            throw new ValidationException(['file' => ['files.validation.too_large']]);
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
            throw new ValidationException(['file' => ['files.validation.save_failed']]);
        }

        $title = $title !== null && trim($title) !== '' ? mb_substr(trim($title), 0, 255) : self::titleFromFilename($originalName);

        $documentId = $this->documents->create([
            'folder_id' => $folderId,
            'filename' => $organizationId . '/' . $storedName,
            'original_filename' => $originalName,
            'title' => $title,
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
        $thumb = $this->thumbCachePath($document);
        if (is_file($thumb)) {
            @unlink($thumb);
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

    // --- Folders -------------------------------------------------------

    public function createFolder(array $actingUser, ?int $parentId, string $name): int
    {
        $this->requireCanManage($actingUser);
        $this->requireFolder($parentId);

        $name = $this->validFolderName($name);
        if ($this->folders->findByName($parentId, $name) !== null) {
            throw new ValidationException(['name' => ['files.validation.folder_exists']]);
        }

        return $this->folders->create([
            'parent_id' => $parentId,
            'name' => $name,
            'created_by_user_id' => (int) $actingUser['id'],
        ]);
    }

    public function renameFolder(array $actingUser, int $id, string $name): array
    {
        $this->requireCanManage($actingUser);
        $folder = $this->requireFolder($id);

        $name = $this->validFolderName($name);
        $parentId = $folder['parent_id'] !== null ? (int) $folder['parent_id'] : null;
        $existing = $this->folders->findByName($parentId, $name);
        if ($existing !== null && (int) $existing['id'] !== $id) {
            throw new ValidationException(['name' => ['files.validation.folder_exists']]);
        }

        $this->folders->rename($id, $name);

        return $folder;
    }

    /** Only an empty folder can be deleted. Returns the deleted folder (for its parent_id). */
    public function deleteFolder(array $actingUser, int $id): array
    {
        $this->requireCanManage($actingUser);
        $folder = $this->requireFolder($id);

        if ($this->folders->hasChildren($id) || $this->documents->hasDocumentsInFolder($id)) {
            throw new ValidationException(['folder' => ['files.validation.folder_not_empty']]);
        }

        $this->folders->deleteFolder($id);

        return $folder;
    }

    public function moveDocument(array $actingUser, int $documentId, ?int $folderId): void
    {
        $this->requireCanManage($actingUser);
        $this->requireFolder($folderId);

        if ($this->documents->findById($documentId) === null) {
            throw new NotFoundException('File not found.');
        }

        $this->documents->moveToFolder($documentId, $folderId);
    }

    // --- Thumbnails ------------------------------------------------------

    /**
     * Path of a cached JPEG thumbnail for an image document, made on
     * first request; NULL when the file isn't an image GD can read
     * (the UI then shows the type icon).
     */
    public function thumbnailPath(array $actingUser, int $id): ?string
    {
        $document = $this->find($actingUser, $id);

        if (!in_array($document['mime_type'], self::IMAGE_TYPES, true)) {
            return null;
        }

        $source = $this->absolutePath($document);
        if (!is_file($source)) {
            return null;
        }

        $cache = $this->thumbCachePath($document);
        if (is_file($cache) && filemtime($cache) >= filemtime($source)) {
            return $cache;
        }

        return $this->generateThumbnail($source, $cache, (string) $document['mime_type']) ? $cache : null;
    }

    private function thumbCachePath(array $document): string
    {
        return rtrim($this->storageRoot, '/') . '/' . (int) $document['organization_id'] . '/thumbs/' . (int) $document['id'] . '.jpg';
    }

    private function generateThumbnail(string $source, string $destination, string $mime): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($source);
        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::THUMB_MAX_PIXELS) {
            return false;
        }

        $this->raiseMemoryLimit();

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/gif' => @imagecreatefromgif($source),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default => false,
        };
        if ($image === false) {
            return false;
        }

        // Phone photos store "rotate me" in EXIF instead of rotating the pixels.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $angle = match ((int) ((@exif_read_data($source) ?: [])['Orientation'] ?? 1)) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
                imagedestroy($image);
                $image = $rotated;
            }
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::THUMB_MAX / max($width, $height));
        $thumbWidth = max(1, (int) round($width * $scale));
        $thumbHeight = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($thumbWidth, $thumbHeight);
        imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255)); // transparent PNG/GIF areas → white
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, $width, $height);
        imagedestroy($image);

        $dir = dirname($destination);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $ok = @imagejpeg($thumb, $destination, 82);
        imagedestroy($thumb);

        return $ok;
    }

    private function raiseMemoryLimit(): void
    {
        $limit = (string) ini_get('memory_limit');
        if ($limit === '-1') {
            return;
        }
        $bytes = (int) $limit * match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
        if ($bytes < 256 * 1024 ** 2) {
            @ini_set('memory_limit', '256M');
        }
    }

    // --- Server inbox (one-time pick-up of files copied onto the server) ---

    public function inboxPath(array $actingUser): string
    {
        return rtrim($this->storageRoot, '/') . '/' . (int) $actingUser['organization_id'] . '/inbox';
    }

    /** Number of files waiting in the organization's inbox (0 when it doesn't exist). */
    public function inboxCount(array $actingUser): int
    {
        if (!$this->canManage($actingUser) || !is_dir($this->inboxPath($actingUser))) {
            return 0;
        }

        $count = 0;
        foreach ($this->scanInbox($this->inboxPath($actingUser), []) as $entry) {
            if ($this->inboxSkipReason($entry) === null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Moves inbox files into the library, recreating their folder
     * tree; title = filename without extension. Stops after
     * INBOX_TIME_BUDGET seconds — calling it again carries on, since
     * moved files are no longer in the inbox. Files that can't be
     * taken are left where they are and listed in 'skipped'.
     *
     * @return array{imported: int, skipped: string[], remaining: int}
     */
    public function importInbox(array $actingUser): array
    {
        $this->requireCanManage($actingUser);

        $inbox = $this->inboxPath($actingUser);
        if (!is_dir($inbox)) {
            return ['imported' => 0, 'skipped' => [], 'remaining' => 0];
        }

        $organizationId = (int) $actingUser['organization_id'];
        $orgDir = rtrim($this->storageRoot, '/') . '/' . $organizationId;
        $started = microtime(true);
        $folderIds = [];
        $imported = 0;
        $skipped = [];

        foreach ($this->scanInbox($inbox, []) as $entry) {
            if (microtime(true) - $started > self::INBOX_TIME_BUDGET) {
                break;
            }

            $relative = implode('/', [...$entry['segments'], $entry['name']]);
            if ($this->inboxSkipReason($entry) !== null) {
                $skipped[] = $relative;
                continue;
            }

            $folderId = $this->ensureFolderPath($entry['segments'], $folderIds, $actingUser);

            $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
            $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
            $storedPath = $orgDir . '/' . $storedName;
            $size = (int) filesize($entry['path']);
            $mimeType = (string) (mime_content_type($entry['path']) ?: 'application/octet-stream');

            if (!@rename($entry['path'], $storedPath)) {
                if (!@copy($entry['path'], $storedPath)) {
                    $skipped[] = $relative;
                    continue;
                }
                @unlink($entry['path']);
            }

            try {
                $this->documents->create([
                    'folder_id' => $folderId,
                    'filename' => $organizationId . '/' . $storedName,
                    'original_filename' => $entry['name'],
                    'title' => mb_substr(self::titleFromFilename($entry['name']), 0, 255),
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'description' => null,
                    'uploaded_by_user_id' => (int) $actingUser['id'],
                ]);
            } catch (\Throwable $e) {
                @rename($storedPath, $entry['path']); // put it back so nothing is lost
                throw $e;
            }
            $imported++;
        }

        $this->removeEmptyDirectories($inbox);

        return ['imported' => $imported, 'skipped' => $skipped, 'remaining' => $this->inboxCount($actingUser)];
    }

    /**
     * Real files under $dir. Symlinks and dot-entries (the old
     * system's .tmb thumbnail cache, hidden files) are never followed,
     * so the walk can't leave the inbox; the old system's top-level
     * index.html is ignored.
     *
     * @return \Generator<array{path: string, segments: string[], name: string}>
     */
    private function scanInbox(string $dir, array $segments): \Generator
    {
        $names = @scandir($dir);
        if ($names === false) {
            return;
        }

        foreach ($names as $name) {
            if ($name === '' || $name[0] === '.') {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_link($path)) {
                continue;
            }
            if (is_dir($path)) {
                yield from $this->scanInbox($path, [...$segments, $name]);
                continue;
            }
            if (!is_file($path) || ($segments === [] && strtolower($name) === 'index.html')) {
                continue;
            }

            yield ['path' => $path, 'segments' => $segments, 'name' => $name];
        }
    }

    private function inboxSkipReason(array $entry): ?string
    {
        foreach ($entry['segments'] as $segment) {
            if ($this->folderNameError($segment) !== null) {
                return 'folder_name';
            }
        }
        if (!mb_check_encoding($entry['name'], 'UTF-8') || strlen($entry['name']) > 255) {
            return 'file_name';
        }
        if ((int) @filesize($entry['path']) === 0) {
            return 'empty';
        }

        return null;
    }

    /** Finds or creates each folder of $segments from the top level down; returns the last one's id (NULL = top level). */
    private function ensureFolderPath(array $segments, array &$cache, array $actingUser): ?int
    {
        $parentId = null;
        $key = '';
        foreach ($segments as $segment) {
            $key .= '/' . $segment;
            if (!isset($cache[$key])) {
                $existing = $this->folders->findByName($parentId, $segment);
                $cache[$key] = $existing !== null
                    ? (int) $existing['id']
                    : $this->folders->create(['parent_id' => $parentId, 'name' => $segment, 'created_by_user_id' => (int) $actingUser['id']]);
            }
            $parentId = $cache[$key];
        }

        return $parentId;
    }

    /** Removes emptied sub-directories of $root (never $root itself, never via symlinks). */
    private function removeEmptyDirectories(string $root): void
    {
        foreach (@scandir($root) ?: [] as $name) {
            $path = $root . '/' . $name;
            if ($name === '.' || $name === '..' || is_link($path) || !is_dir($path)) {
                continue;
            }
            $this->removeEmptyDirectories($path);
            @rmdir($path); // only succeeds when empty
        }
    }

    // --- Folder helpers --------------------------------------------------

    /** Returns the folder, or NULL for the top level; 404 for an unknown / other organization's folder. */
    private function requireFolder(?int $folderId): ?array
    {
        if ($folderId === null) {
            return null;
        }

        $folder = $this->folders->findById($folderId);
        if ($folder === null) {
            throw new NotFoundException('Folder not found.');
        }

        return $folder;
    }

    private function validFolderName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $error = $this->folderNameError($name);
        if ($error !== null) {
            throw new ValidationException(['name' => [$error]]);
        }

        return $name;
    }

    private function folderNameError(string $name): ?string
    {
        if (trim($name) === '') {
            return 'files.validation.folder_name_required';
        }
        if (!mb_check_encoding($name, 'UTF-8') || $name === '.' || $name === '..' || preg_match('#[/\\\\\x00-\x1F]#', $name)) {
            return 'files.validation.folder_name_invalid';
        }
        if (mb_strlen($name) > self::FOLDER_NAME_MAX) {
            return 'files.validation.folder_name_too_long';
        }

        return null;
    }

    /** Root-first list of the folder and its parents. */
    private function ancestry(int $folderId, array $allFolders): array
    {
        $chain = [];
        $id = $folderId;
        while ($id !== null && isset($allFolders[$id]) && count($chain) < 64) {
            array_unshift($chain, $allFolders[$id]);
            $id = $allFolders[$id]['parent_id'] !== null ? (int) $allFolders[$id]['parent_id'] : null;
        }

        return $chain;
    }

    private function pathLabel(int $folderId, array $allFolders): string
    {
        return implode(' / ', array_map(fn ($f) => (string) $f['name'], $this->ancestry($folderId, $allFolders)));
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
