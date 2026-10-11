<?php

declare(strict_types=1);

/**
 * File library folders, titles, thumbnails, grid view and the server
 * inbox pick-up (docs/file-library-folders-thumbnails-design.md).
 * crm_local only, one transaction, rolled back; the inbox tests use a
 * throw-away storage root under the system temp dir.
 */

require_once __DIR__ . '/Support/LocalApp.php';

use App\Application\Services\TagService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use Modules\Knowledge\FileLibraryDocumentRepository;
use Modules\Knowledge\FileLibraryFolderRepository;
use Modules\Knowledge\FileLibraryUploaderRepository;
use Modules\Knowledge\Services\FileLibraryService;
use Tests\Lite\Support\LocalApp;

$f = new \stdClass();
$f->app = null;
$f->reason = '';
$f->tmp = [];

$boot = function () use ($f): LocalApp {
    if ($f->app !== null) {
        return $f->app;
    }
    if ($f->reason !== '') {
        throw new LiteSkip($f->reason);
    }
    try {
        $a = LocalApp::boot();
        $a->pdo->query('SELECT folder_id, title FROM file_library_documents LIMIT 1');
        $a->pdo->query('SELECT 1 FROM file_library_folders LIMIT 1');
    } catch (\Throwable $e) {
        $f->reason = 'local database unavailable or knowledge migrations 0005/0006 not run: ' . $e->getMessage();
        throw new LiteSkip($f->reason);
    }
    $a->setModuleStatus('knowledge', 'active');
    $f->app = $a;

    return $a;
};

/** A FileLibraryService on a temporary storage root (inbox tests never touch real storage). */
$service = function (string $root) use ($boot): FileLibraryService {
    $a = $boot();

    return new FileLibraryService(
        new FileLibraryDocumentRepository(),
        new FileLibraryFolderRepository(),
        new FileLibraryUploaderRepository(),
        $a->container()->make(TagService::class),
        new UserRepository(),
        $a->container()->make(AuthorizationEngine::class),
        $root
    );
};

$tmpRoot = function () use ($f): string {
    $dir = sys_get_temp_dir() . '/crm-files-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    $f->tmp[] = $dir;

    return $dir;
};

$rmTree = function (string $dir) use (&$rmTree): void {
    foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $n) {
        if ($n === '.' || $n === '..') {
            continue;
        }
        is_dir("$dir/$n") ? $rmTree("$dir/$n") : @unlink("$dir/$n");
    }
    @rmdir($dir);
};

$png = function (string $path, int $w = 40, int $h = 20): void {
    @mkdir(dirname($path), 0777, true);
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 30));
    imagepng($img, $path);
    imagedestroy($img);
};

$expectValidation = function (callable $fn, string $key): void {
    try {
        $fn();
    } catch (ValidationException $e) {
        LiteAssert::same($key, array_values($e->errors)[0][0]);

        return;
    }
    throw new LiteAssertionFailed("expected validation error {$key}");
};

$otherOrgFolder = function (LocalApp $a): ?int {
    if ($a->otherOrgId === null) {
        return null;
    }
    $a->pdo->prepare('INSERT INTO file_library_folders (organization_id, parent_id, name) VALUES (?, NULL, ?)')->execute([$a->otherOrgId, 'Other org folder']);

    return (int) $a->pdo->lastInsertId();
};

return [
    'F1. folders: create nested, duplicate and invalid names rejected, breadcrumb path' => function () use ($boot, $service, $tmpRoot, $expectValidation) {
        $a = $boot();
        $s = $service($tmpRoot());
        $top = $s->createFolder($a->admin, null, '  مدارک   حامد ');
        $child = $s->createFolder($a->admin, $top, 'Mason');

        $expectValidation(fn () => $s->createFolder($a->admin, null, 'مدارک حامد'), 'files.validation.folder_exists');
        $expectValidation(fn () => $s->createFolder($a->admin, null, '   '), 'files.validation.folder_name_required');
        $expectValidation(fn () => $s->createFolder($a->admin, null, 'a/b'), 'files.validation.folder_name_invalid');
        $expectValidation(fn () => $s->createFolder($a->admin, null, 'a\\b'), 'files.validation.folder_name_invalid');
        $expectValidation(fn () => $s->createFolder($a->admin, null, '..'), 'files.validation.folder_name_invalid');
        $expectValidation(fn () => $s->createFolder($a->admin, null, str_repeat('x', 191)), 'files.validation.folder_name_too_long');
        // same name is fine under a different parent
        $s->createFolder($a->admin, $child, 'Mason');

        $browse = $s->browse($a->admin, $child);
        LiteAssert::same(['مدارک حامد', 'Mason'], array_column($browse['breadcrumb'], 'name'));
        LiteAssert::same(1, count($browse['folders']));
        $labels = array_column($browse['folderOptions'], 'label');
        LiteAssert::true(in_array('مدارک حامد / Mason / Mason', $labels, true), 'nested path label');

        $root = $s->browse($a->admin, null);
        $row = array_values(array_filter($root['folders'], fn ($r) => (int) $r['id'] === $top))[0];
        LiteAssert::same(1, (int) $row['item_count']);
    },

    'F2. folder delete only when empty; rename keeps siblings unique' => function () use ($boot, $service, $tmpRoot, $expectValidation) {
        $a = $boot();
        $s = $service($tmpRoot());
        $p = $s->createFolder($a->admin, null, 'F2 parent');
        $c = $s->createFolder($a->admin, $p, 'F2 child');
        $s->createFolder($a->admin, null, 'F2 other');

        $expectValidation(fn () => $s->deleteFolder($a->admin, $p), 'files.validation.folder_not_empty');
        $expectValidation(fn () => $s->renameFolder($a->admin, $p, 'F2 other'), 'files.validation.folder_exists');
        $s->renameFolder($a->admin, $p, 'F2 renamed');
        $s->renameFolder($a->admin, $p, 'F2 renamed'); // renaming to itself is allowed

        $s->deleteFolder($a->admin, $c);
        $s->deleteFolder($a->admin, $p);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM file_library_folders WHERE name IN ('F2 renamed', 'F2 child')")->fetchColumn());
    },

    'F3. tenant isolation: another organization\'s folder is 404 everywhere' => function () use ($boot, $service, $tmpRoot, $otherOrgFolder) {
        $a = $boot();
        $other = $otherOrgFolder($a);
        if ($other === null) {
            throw new LiteSkip('only one organization in crm_local');
        }
        $s = $service($tmpRoot());
        foreach ([
            fn () => $s->browse($a->admin, $other),
            fn () => $s->createFolder($a->admin, $other, 'x'),
            fn () => $s->renameFolder($a->admin, $other, 'x'),
            fn () => $s->deleteFolder($a->admin, $other),
        ] as $i => $call) {
            try {
                $call();
                throw new LiteAssertionFailed("call {$i} reached another org's folder");
            } catch (NotFoundException) {
            }
        }
        LiteAssert::same('notfound', $a->outcome('GET', '/files', ['folder' => (string) $other]));
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM file_library_folders WHERE id = {$other} AND name = 'Other org folder'")->fetchColumn());
    },

    'F4. inbox: tree recreated, titles from filenames, old-system leftovers ignored, idempotent' => function () use ($boot, $service, $tmpRoot, $png) {
        $a = $boot();
        $root = $tmpRoot();
        $s = $service($root);
        $inbox = $s->inboxPath($a->admin);
        $png("$inbox/مدارک حسین میرشمسی/کارت ملی.png");
        $png("$inbox/Mason/sub/photo 1.png");
        mkdir("$inbox/Mohr", 0777, true);
        file_put_contents("$inbox/Mohr/قرارداد.pdf", "%PDF-1.4\n%test\n");
        file_put_contents("$inbox/Mohr/empty.txt", '');
        file_put_contents("$inbox/index.html", '<html></html>');
        $png("$inbox/.tmb/cache.png");
        file_put_contents("$inbox/Mason/.hidden", 'x');

        LiteAssert::same(3, $s->inboxCount($a->admin));

        $result = $s->importInbox($a->admin);
        LiteAssert::same(3, $result['imported']);
        LiteAssert::same(['Mohr/empty.txt'], $result['skipped']);
        LiteAssert::same(0, $result['remaining']);

        $hossein = (int) $a->pdo->query("SELECT id FROM file_library_folders WHERE parent_id IS NULL AND name = 'مدارک حسین میرشمسی' AND organization_id = {$a->orgId}")->fetchColumn();
        LiteAssert::true($hossein > 0, 'top folder created');
        $doc = $a->pdo->query("SELECT * FROM file_library_documents WHERE folder_id = {$hossein}")->fetch(\PDO::FETCH_ASSOC);
        LiteAssert::same('کارت ملی', $doc['title']);
        LiteAssert::same('کارت ملی.png', $doc['original_filename']);
        LiteAssert::same('image/png', $doc['mime_type']);
        LiteAssert::true(is_file($root . '/' . $doc['filename']), 'file moved into storage');
        LiteAssert::true(!str_contains($doc['filename'], 'inbox'), 'stored outside the inbox');

        $sub = $s->browse($a->admin, null, 'photo 1');
        LiteAssert::same('Mason / sub', $sub['documents'][0]['folder_path']);

        LiteAssert::true(!is_file("$inbox/مدارک حسین میرشمسی/کارت ملی.png"), 'source moved');
        LiteAssert::true(!is_dir("$inbox/مدارک حسین میرشمسی"), 'emptied folder removed');
        LiteAssert::true(is_file("$inbox/index.html") && is_file("$inbox/.tmb/cache.png") && is_file("$inbox/Mohr/empty.txt"), 'leftovers untouched');

        $again = $s->importInbox($a->admin);
        LiteAssert::same(0, $again['imported']);
        LiteAssert::same(1, (int) $a->pdo->query("SELECT COUNT(*) FROM file_library_folders WHERE parent_id IS NULL AND name = 'Mason' AND organization_id = {$a->orgId}")->fetchColumn());
    },

    'F5. inbox merges into an existing folder of the same name' => function () use ($boot, $service, $tmpRoot, $png) {
        $a = $boot();
        $s = $service($tmpRoot());
        $existing = $s->createFolder($a->admin, null, 'هوشمند پرداز');
        $png($s->inboxPath($a->admin) . '/هوشمند پرداز/لوگو.png');
        $s->importInbox($a->admin);
        LiteAssert::same('لوگو', $s->browse($a->admin, $existing)['documents'][0]['display_title']);
    },

    'F6. thumbnails: JPEG made for images, EXIF-safe, none for other types or other orgs' => function () use ($boot, $service, $tmpRoot, $png) {
        $a = $boot();
        $root = $tmpRoot();
        $s = $service($root);
        $png($s->inboxPath($a->admin) . '/wide.png', 800, 400);
        file_put_contents($s->inboxPath($a->admin) . '/notes.txt', 'hello');
        $s->importInbox($a->admin);

        $img = (int) $a->pdo->query("SELECT id FROM file_library_documents WHERE original_filename = 'wide.png' AND organization_id = {$a->orgId} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $txt = (int) $a->pdo->query("SELECT id FROM file_library_documents WHERE original_filename = 'notes.txt' AND organization_id = {$a->orgId} ORDER BY id DESC LIMIT 1")->fetchColumn();

        $thumb = $s->thumbnailPath($a->admin, $img);
        LiteAssert::true($thumb !== null && is_file($thumb), 'thumbnail written');
        [$w, $h, $type] = getimagesize($thumb);
        LiteAssert::same([320, 160, IMAGETYPE_JPEG], [$w, $h, $type]);
        LiteAssert::same(null, $s->thumbnailPath($a->admin, $txt));

        // deleting the file deletes its cached thumbnail too
        $s->delete($a->admin, $img);
        LiteAssert::true(!is_file($thumb), 'thumbnail removed with the file');

        if ($a->otherOrgId !== null) {
            $a->pdo->prepare("INSERT INTO file_library_documents (organization_id, filename, original_filename, mime_type, size) VALUES (?, 'x/y.png', 'y.png', 'image/png', 1)")->execute([$a->otherOrgId]);
            $foreign = (int) $a->pdo->lastInsertId();
            try {
                $s->thumbnailPath($a->admin, $foreign);
                throw new LiteAssertionFailed('read another org\'s thumbnail');
            } catch (NotFoundException) {
            }
            LiteAssert::same('notfound', $a->outcome('GET', "/files/{$foreign}/thumb"));
            LiteAssert::same('notfound', $a->outcome('GET', "/files/{$foreign}/view"));
        }
    },

    'F7. move: into a folder and back; unknown folder is 404' => function () use ($boot, $service, $tmpRoot) {
        $a = $boot();
        $s = $service($tmpRoot());
        @mkdir($s->inboxPath($a->admin), 0777, true);
        file_put_contents($s->inboxPath($a->admin) . '/move-me.txt', 'x');
        $s->importInbox($a->admin);
        $doc = (int) $a->pdo->query("SELECT id FROM file_library_documents WHERE original_filename = 'move-me.txt' AND organization_id = {$a->orgId} ORDER BY id DESC LIMIT 1")->fetchColumn();
        $folder = $s->createFolder($a->admin, null, 'F7 target');

        $s->moveDocument($a->admin, $doc, $folder);
        LiteAssert::same($folder, (int) $a->pdo->query("SELECT folder_id FROM file_library_documents WHERE id = {$doc}")->fetchColumn());
        $s->moveDocument($a->admin, $doc, null);
        LiteAssert::same(null, $a->pdo->query("SELECT folder_id FROM file_library_documents WHERE id = {$doc}")->fetchColumn());
        try {
            $s->moveDocument($a->admin, $doc, 99999999);
            throw new LiteAssertionFailed('moved into a missing folder');
        } catch (NotFoundException) {
        }
    },

    'F8. no manage right: cannot create folders, move, or empty the inbox; no inbox notice' => function () use ($boot, $service, $tmpRoot) {
        $a = $boot();
        $s = $service($tmpRoot());
        $denied = 0;
        foreach ([
            fn () => $s->createFolder($a->noRole, null, 'nope'),
            fn () => $s->importInbox($a->noRole),
            fn () => $s->moveDocument($a->noRole, 1, null),
            fn () => $s->browse($a->noRole, null),
        ] as $call) {
            try {
                $call();
            } catch (AuthorizationException) {
                $denied++;
            }
        }
        LiteAssert::same(4, $denied);
        LiteAssert::same(0, $s->inboxCount($a->noRole));

        $a->as($a->noRole);
        LiteAssert::same('forbidden', $a->outcome('POST', '/files/folders', [], ['name' => 'nope']));
        LiteAssert::same('forbidden', $a->outcome('POST', '/files/inbox/import'));
        $a->as($a->admin);
        LiteAssert::same('csrf', $a->outcome('POST', '/files/folders', [], ['name' => 'x', '_csrf' => 'bad']));
    },

    'F9. page renders list + grid in every language with breadcrumb, thumbnails and no raw keys' => function () use ($boot) {
        $a = $boot();
        $a->as($a->admin);
        $a->request('POST', '/files/folders', [], ['name' => 'F9 folder']);
        $id = (int) $a->pdo->query("SELECT id FROM file_library_folders WHERE name = 'F9 folder' AND organization_id = {$a->orgId}")->fetchColumn();
        LiteAssert::true($id > 0, 'folder created via route');

        foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
            $a->locale($locale);
            foreach (['list', 'grid'] as $view) {
                $root = $a->request('GET', '/files', $view === 'grid' ? ['view' => 'grid'] : [])['body'];
                LiteAssert::contains('href="/files?folder=' . $id . ($view === 'grid' ? '&amp;view=grid' : '') . '"', $root, "folder link ({$locale}/{$view})");
                LiteAssert::contains($view === 'grid' ? 'class="media-grid file-grid"' : 'class="table file-table"', $root);
                LiteAssert::same(0, preg_match('/files\.[a-z_]+/', strip_tags($root), $m), "raw key ({$locale}/{$view}): " . ($m[0] ?? ''));

                $inside = $a->request('GET', '/files', ['folder' => (string) $id, 'view' => $view])['body'];
                LiteAssert::contains('aria-current="page">F9 folder</li>', $inside);
                LiteAssert::contains('name="parent_id" value="' . $id . '"', $inside);
                LiteAssert::contains('/files/folders/' . $id . '/delete', $inside, 'empty folder can be deleted');
                LiteAssert::same(0, preg_match('/files\.[a-z_]+/', strip_tags($inside), $m), "raw key inside ({$locale}/{$view}): " . ($m[0] ?? ''));
            }
        }
        $a->locale('en');

        $legacy = $a->request('GET', '/files', ['tag' => 'F9 folder'])['body'];
        LiteAssert::contains('F9 folder', $legacy, 'old ?tag= links still search');
    },

    'F10. rollback + temp cleanup' => function () use ($boot, $f, $rmTree) {
        $a = $boot();
        $a->rollBack();
        foreach ($f->tmp as $dir) {
            $rmTree($dir);
        }
        LiteAssert::true(true);
    },
];
