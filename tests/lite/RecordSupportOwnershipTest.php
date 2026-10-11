<?php

declare(strict_types=1);

/**
 * Record support ownership (docs/record-support-ownership-design.md).
 *
 * Security boundary for every note / tag / attachment route, in order,
 * before any write:
 *   1. the record type is valid and viewable;
 *   2. the parent exists in the user's organization;
 *   3. the child really belongs to that parent;
 *   4. only then the operation runs.
 * Covers /records/{type}/{id}/... for contact, company, lead, contract,
 * project, plus the Sales task and Support ticket routes that had the
 * same hole. Then regression: every legitimate operation still works.
 *
 * crm_local only, one transaction, rolled back; attachment files written
 * by the suite are removed at the end.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use Modules\Contracts\Services\ContractService;
use Modules\Sales\Services\TaskService;
use Modules\Support\Services\TicketService;
use Tests\Lite\Support\ProjectsLite;

$booter = ProjectsLite::booter(true);
$f = new \stdClass();
$f->ready = false;
$f->files = [];
register_shutdown_function(function () use ($f) {
    foreach ($f->files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
});

/** Make sure a module is active and entitled for the org (inside the transaction). */
$entitle = function ($a, string $key): void {
    $id = $a->pdo->query('SELECT id FROM modules WHERE `key` = ' . $a->pdo->quote($key))->fetchColumn();
    if ($id === false) {
        throw new LiteSkip("module {$key} not installed in crm_local");
    }
    $a->pdo->prepare("UPDATE modules SET status = 'active' WHERE id = ?")->execute([(int) $id]);
    $a->pdo->prepare('DELETE FROM organization_modules WHERE organization_id = ? AND module_id = ?')->execute([$a->orgId, (int) $id]);
    $a->pdo->prepare('INSERT INTO organization_modules (organization_id, module_id, enabled) VALUES (?, ?, 1)')->execute([$a->orgId, (int) $id]);
};

$boot = function () use ($booter, $f, $entitle) {
    $a = $booter();
    if ($f->ready) {
        return $a;
    }
    $a->as($a->admin);
    $entitle($a, 'sales');
    $entitle($a, 'support');
    $insert = function (string $sql, array $params) use ($a): int {
        $a->pdo->prepare($sql)->execute($params);

        return (int) $a->pdo->lastInsertId();
    };

    // Two parents (A, B) of every record type, all in the admin's organization.
    $f->parents = [];
    foreach (['A', 'B'] as $k) {
        $f->parents['contact'][$k] = $insert('INSERT INTO contacts (organization_id, first_name, last_name) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest', "Owner {$k}"]);
        $f->parents['company'][$k] = $insert('INSERT INTO companies (organization_id, name) VALUES (?, ?)', [$a->orgId, "LiteTest Co {$k}"]);
        $f->parents['lead'][$k] = $insert('INSERT INTO leads (organization_id, first_name) VALUES (?, ?)', [$a->orgId, "LiteTest Lead {$k}"]);
        $f->parents['contract'][$k] = (int) $a->container()->make(ContractService::class)->create($a->admin, ['title' => "LiteTest Ctr {$k}"])['id'];
        $f->parents['project'][$k] = (int) ProjectsLite::service($a)->create($a->admin, ['name' => "LiteTest Prj {$k}"])['id'];
    }
    // Raw rows: TaskService/TicketService::create() open their own DB transaction,
    // which can't nest inside this suite's rollback transaction.
    $f->task = [
        'A' => $insert('INSERT INTO tasks (organization_id, title, created_by_user_id) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest task A', (int) $a->admin['id']]),
        'B' => $insert('INSERT INTO tasks (organization_id, title, created_by_user_id) VALUES (?, ?, ?)', [$a->orgId, 'LiteTest task B', (int) $a->admin['id']]),
    ];
    $f->ticket = $insert('INSERT INTO tickets (organization_id, subject) VALUES (?, ?)', [$a->orgId, 'LiteTest ticket']);

    // Another organization's record + tag (+ its taggables row), if there is one.
    $f->foreign = null;
    if ($a->otherOrgId !== null) {
        $contact = $insert('INSERT INTO contacts (organization_id, first_name) VALUES (?, ?)', [$a->otherOrgId, 'LiteTest XORG']);
        $tag = $insert('INSERT INTO tags (organization_id, name) VALUES (?, ?)', [$a->otherOrgId, 'LiteTest XORG tag ' . bin2hex(random_bytes(3))]);
        $a->pdo->prepare('INSERT INTO taggables (tag_id, taggable_type, taggable_id) VALUES (?, ?, ?)')->execute([$tag, 'contact', $contact]);
        $note = $insert('INSERT INTO notes (organization_id, notable_type, notable_id, user_id, body) VALUES (?, ?, ?, NULL, ?)', [$a->otherOrgId, 'contact', $contact, 'LiteTest XORG note']);
        $f->foreign = ['contact' => $contact, 'tag' => $tag, 'note' => $note];
    }
    $f->ready = true;

    return $a;
};

/** A note on $type #$id through the real service; returns its id. */
$note = function ($a, string $type, int $id, string $body = 'LiteTest note'): int {
    return (int) $a->container()->make(NoteService::class)->add($type, $id, (int) $a->admin['id'], $body)['id'];
};

/** An attachment row + a real stored file for $type #$id; returns [id, absolute path]. */
$attachment = function ($a, string $type, int $id) use ($f): array {
    $stored = $a->orgId . '/litetest-' . bin2hex(random_bytes(8)) . '.txt';
    $a->pdo->prepare('INSERT INTO attachments (organization_id, attachable_type, attachable_id, user_id, filename, stored_path, mime_type, size) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$a->orgId, $type, $id, (int) $a->admin['id'], 'litetest.txt', $stored, 'text/plain', 5]);
    $attId = (int) $a->pdo->lastInsertId();
    $path = $a->container()->make(AttachmentService::class)->absolutePath(['stored_path' => $stored]);
    @mkdir(dirname($path), 0755, true);
    file_put_contents($path, 'hello');
    $f->files[] = $path;

    return [$attId, $path];
};

$count = fn ($a, string $table, int $id): int => (int) $a->pdo->query("SELECT COUNT(*) FROM {$table} WHERE id = {$id}")->fetchColumn();
$types = ['contact', 'company', 'lead', 'contract', 'project'];

$tests = [];

// ---- Security: notes -------------------------------------------------------
$tests['S1. note of parent A cannot be deleted through parent B (every record type) → 404, note kept'] = function () use ($boot, $f, $note, $count, $types) {
    $a = $boot();
    $a->as($a->admin);
    $wrong = [];
    foreach ($types as $type) {
        $noteId = $note($a, $type, $f->parents[$type]['A']);
        $got = $a->outcome('POST', "/records/{$type}/{$f->parents[$type]['B']}/notes/{$noteId}/delete");
        if ($got !== 'notfound' || $count($a, 'notes', $noteId) !== 1) {
            $wrong[] = "{$type}: {$got}, kept=" . $count($a, 'notes', $noteId);
        }
    }
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
};

$tests['S2. a note of another type with the parent\'s numeric id is refused (type must match too)'] = function () use ($boot, $f, $count) {
    $a = $boot();
    $a->as($a->admin);
    $company = $f->parents['company']['A'];
    // notable_type = contact, notable_id = the company's id: same number, other type.
    $a->pdo->prepare("INSERT INTO notes (organization_id, notable_type, notable_id, user_id, body) VALUES (?, 'contact', ?, NULL, 'LiteTest type clash')")->execute([$a->orgId, $company]);
    $noteId = (int) $a->pdo->lastInsertId();
    LiteAssert::same('notfound', $a->outcome('POST', "/records/company/{$company}/notes/{$noteId}/delete"));
    LiteAssert::same(1, $count($a, 'notes', $noteId));
};

$tests['S3. the type check runs BEFORE the delete: unknown type, module off, user without role → 404, note kept'] = function () use ($boot, $f, $note, $count) {
    $a = $boot();
    $a->as($a->admin);
    $contactNote = $note($a, 'contact', $f->parents['contact']['A']);
    LiteAssert::same('notfound', $a->outcome('POST', "/records/nope/{$f->parents['contact']['A']}/notes/{$contactNote}/delete"), 'unknown type');
    LiteAssert::same(1, $count($a, 'notes', $contactNote));

    $contractNote = $note($a, 'contract', $f->parents['contract']['A']);
    $a->setOrgEntitled('contracts', false);
    try {
        LiteAssert::same('notfound', $a->outcome('POST', "/records/contract/{$f->parents['contract']['A']}/notes/{$contractNote}/delete"), 'module off');
    } finally {
        $a->setOrgEntitled('contracts', true);
    }
    LiteAssert::same(1, $count($a, 'notes', $contractNote));

    $a->as($a->noRole);
    LiteAssert::same('notfound', $a->outcome('POST', "/records/contact/{$f->parents['contact']['A']}/notes/{$contactNote}/delete"), 'no role');
    $a->as($a->admin);
    LiteAssert::same(1, $count($a, 'notes', $contactNote));
};

// ---- Security: attachments -------------------------------------------------
$tests['S4. attachment of parent A: delete and download through parent B → 404; row and stored file kept'] = function () use ($boot, $f, $attachment, $count, $types) {
    $a = $boot();
    $a->as($a->admin);
    $wrong = [];
    foreach ($types as $type) {
        [$attId, $path] = $attachment($a, $type, $f->parents[$type]['A']);
        $b = $f->parents[$type]['B'];
        $del = $a->outcome('POST', "/records/{$type}/{$b}/attachments/{$attId}/delete");
        $get = $a->outcome('GET', "/records/{$type}/{$b}/attachments/{$attId}/download");
        if ($del !== 'notfound' || $get !== 'notfound' || $count($a, 'attachments', $attId) !== 1 || !is_file($path)) {
            $wrong[] = "{$type}: delete={$del} download={$get} row=" . $count($a, 'attachments', $attId) . ' file=' . (is_file($path) ? 'yes' : 'NO');
        }
    }
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
};

// ---- Security: tags (taggables has no organization_id) ----------------------
$tests['S5. another organization\'s tag cannot be detached (through my record or theirs); its taggables row is untouched'] = function () use ($boot, $f) {
    $a = $boot();
    if ($f->foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $row = fn () => (int) $a->pdo->query("SELECT COUNT(*) FROM taggables WHERE tag_id = {$f->foreign['tag']} AND taggable_type = 'contact' AND taggable_id = {$f->foreign['contact']}")->fetchColumn();
    LiteAssert::same('notfound', $a->outcome('POST', "/records/contact/{$f->foreign['contact']}/tags/{$f->foreign['tag']}/delete"), 'their record');
    LiteAssert::same('notfound', $a->outcome('POST', "/records/contact/{$f->parents['contact']['A']}/tags/{$f->foreign['tag']}/delete"), 'my record');
    LiteAssert::same(1, $row());
    // Service level too: detach() of a tag outside the organization is a no-op.
    $a->container()->make(TagService::class)->detach('contact', $f->foreign['contact'], $f->foreign['tag'], (int) $a->admin['id']);
    LiteAssert::same(1, $row(), 'detach() no-op for a foreign tag');
};

$tests['S6. my tag cannot be attached to another organization\'s record; their page never shows it'] = function () use ($boot, $f) {
    $a = $boot();
    if ($f->foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    $name = 'LiteTest leak ' . bin2hex(random_bytes(3));
    LiteAssert::same('notfound', $a->outcome('POST', "/records/contact/{$f->foreign['contact']}/tags", [], ['name' => $name]));
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM taggables WHERE taggable_type = 'contact' AND taggable_id = {$f->foreign['contact']} AND tag_id <> {$f->foreign['tag']}")->fetchColumn(), 'no taggables row');
    LiteAssert::same(0, (int) $a->pdo->query('SELECT COUNT(*) FROM tags WHERE name = ' . $a->pdo->quote($name))->fetchColumn(), 'not even the tag is created');
};

// ---- Security: parent must exist in the organization ------------------------
$tests['S7. writes to a missing or foreign parent → 404 and nothing written (note, tag, attachment, timeline)'] = function () use ($boot, $f) {
    $a = $boot();
    $a->as($a->admin);
    $targets = [['contact', 999999999], ['project', 999999999], ['contract', 999999999]];
    if ($f->foreign !== null) {
        $targets[] = ['contact', $f->foreign['contact']];
    }
    $wrong = [];
    foreach ($targets as [$type, $id]) {
        $before = (int) $a->pdo->query("SELECT (SELECT COUNT(*) FROM notes WHERE notable_type = '{$type}' AND notable_id = {$id}) + (SELECT COUNT(*) FROM timeline_events WHERE subject_type = '{$type}' AND subject_id = {$id}) + (SELECT COUNT(*) FROM taggables WHERE taggable_type = '{$type}' AND taggable_id = {$id})")->fetchColumn();
        foreach ([['POST', 'notes', ['body' => 'orphan']], ['POST', 'tags', ['name' => 'LiteTest orphan']], ['POST', 'attachments', []]] as [$m, $what, $body]) {
            $got = $a->outcome($m, "/records/{$type}/{$id}/{$what}", [], $body);
            if ($got !== 'notfound') {
                $wrong[] = "{$type}#{$id} {$what}: {$got}";
            }
        }
        $after = (int) $a->pdo->query("SELECT (SELECT COUNT(*) FROM notes WHERE notable_type = '{$type}' AND notable_id = {$id}) + (SELECT COUNT(*) FROM timeline_events WHERE subject_type = '{$type}' AND subject_id = {$id}) + (SELECT COUNT(*) FROM taggables WHERE taggable_type = '{$type}' AND taggable_id = {$id})")->fetchColumn();
        if ($after !== $before) {
            $wrong[] = "{$type}#{$id}: rows written";
        }
        if ($a->outcome('GET', "/records/{$type}/{$id}/attachments/1/download") !== 'notfound') {
            $wrong[] = "{$type}#{$id} download";
        }
    }
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
};

$tests['S8. another organization\'s note id on my own record → 404, their note kept'] = function () use ($boot, $f, $count) {
    $a = $boot();
    if ($f->foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    LiteAssert::same('notfound', $a->outcome('POST', "/records/contact/{$f->parents['contact']['A']}/notes/{$f->foreign['note']}/delete"));
    LiteAssert::same('notfound', $a->outcome('POST', "/records/contact/{$f->foreign['contact']}/notes/{$f->foreign['note']}/delete"));
    LiteAssert::same(1, $count($a, 'notes', $f->foreign['note']));
};

$tests['S8b. defense in depth: a module finder that leaks another organization\'s row is still refused'] = function () use ($boot, $f) {
    $a = $boot();
    if ($f->foreign === null) {
        throw new LiteSkip('only one organization');
    }
    $a->as($a->admin);
    // A test-only record type whose finder is (wrongly) NOT tenant-scoped:
    // the controller's own organization re-check must still say 404.
    $a->container()->make(\App\Infrastructure\Records\RecordTypeRegistry::class)->register(new \App\Infrastructure\Records\RecordType(
        key: 'litetest_leaky',
        module: null,
        viewPermission: 'contacts.view',
        labelKey: 'nav.contacts',
        showPath: '/contacts/{id}',
        finder: fn (int $id): ?array => $a->pdo->query("SELECT * FROM contacts WHERE id = {$id}")->fetch(\PDO::FETCH_ASSOC) ?: null,
    ));
    $foreign = $f->foreign['contact'];
    LiteAssert::same('notfound', $a->outcome('POST', "/records/litetest_leaky/{$foreign}/notes", [], ['body' => 'leak probe']));
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM notes WHERE notable_type = 'litetest_leaky'")->fetchColumn());
    // …while a row of my own organization is accepted through the same finder.
    $mine = $f->parents['contact']['A'];
    LiteAssert::same(302, $a->request('POST', "/records/litetest_leaky/{$mine}/notes", [], ['body' => 'LiteTest own probe'])['status']);
};

// ---- Security: Sales task + Support ticket routes ---------------------------
$tests['S9. task routes: a contact\'s note / attachment, or task A\'s comment via task B → 404, kept'] = function () use ($boot, $f, $note, $attachment, $count) {
    $a = $boot();
    $a->as($a->admin);
    $contactNote = $note($a, 'contact', $f->parents['contact']['A']);
    [$contactAtt, $contactPath] = $attachment($a, 'contact', $f->parents['contact']['A']);
    $commentA = $note($a, 'task', $f->task['A'], 'LiteTest comment A');
    [$attA, $pathA] = $attachment($a, 'task', $f->task['A']);
    $tB = $f->task['B'];
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$tB}/comments/{$contactNote}/delete"), 'contact note via task');
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$tB}/comments/{$commentA}/delete"), 'task A comment via task B');
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$tB}/attachments/{$contactAtt}/delete"), 'contact file via task');
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/{$tB}/attachments/{$attA}/delete"), 'task A file via task B');
    LiteAssert::same('notfound', $a->outcome('POST', "/tasks/999999999/comments/{$commentA}/delete"), 'missing task');
    foreach ([['notes', $contactNote], ['notes', $commentA], ['attachments', $contactAtt], ['attachments', $attA]] as [$table, $id]) {
        LiteAssert::same(1, $count($a, $table, $id), "{$table} #{$id} kept");
    }
    LiteAssert::true(is_file($contactPath) && is_file($pathA), 'files kept');
};

$tests['S10. ticket route: a contact\'s (or other record\'s) attachment via a ticket → 404, row and file kept'] = function () use ($boot, $f, $attachment, $count) {
    $a = $boot();
    $a->as($a->admin);
    [$contactAtt, $path] = $attachment($a, 'contact', $f->parents['contact']['A']);
    [$projectAtt, $path2] = $attachment($a, 'project', $f->parents['project']['A']);
    LiteAssert::same('notfound', $a->outcome('POST', "/support/tickets/{$f->ticket}/attachments/{$contactAtt}/delete"));
    LiteAssert::same('notfound', $a->outcome('POST', "/support/tickets/{$f->ticket}/attachments/{$projectAtt}/delete"));
    LiteAssert::same('notfound', $a->outcome('POST', "/support/tickets/999999999/attachments/{$contactAtt}/delete"), 'missing ticket');
    LiteAssert::same(1, $count($a, 'attachments', $contactAtt));
    LiteAssert::same(1, $count($a, 'attachments', $projectAtt));
    LiteAssert::true(is_file($path) && is_file($path2), 'files kept');
};

// ---- Regression: legitimate operations still work ---------------------------
$tests['R1. every record type: add + delete note, add + remove tag, download + delete attachment with the right parent'] = function () use ($boot, $f, $attachment, $count, $types) {
    $a = $boot();
    $a->as($a->admin);
    $wrong = [];
    foreach ($types as $type) {
        $id = $f->parents[$type]['A'];
        $r = $a->request('POST', "/records/{$type}/{$id}/notes", [], ['body' => "LiteTest regression {$type}"]);
        $noteId = (int) $a->pdo->query("SELECT id FROM notes WHERE notable_type = '{$type}' AND notable_id = {$id} AND body = 'LiteTest regression {$type}'")->fetchColumn();
        if ($r['status'] !== 302 || $noteId === 0) {
            $wrong[] = "{$type}: add note {$r['status']}";
            continue;
        }
        $a->request('POST', "/records/{$type}/{$id}/notes/{$noteId}/delete");
        if ($count($a, 'notes', $noteId) !== 0) {
            $wrong[] = "{$type}: note not deleted";
        }

        $tagName = "LiteTest rtag {$type}";
        $a->request('POST', "/records/{$type}/{$id}/tags", [], ['name' => $tagName]);
        $tagId = (int) $a->pdo->query('SELECT id FROM tags WHERE organization_id = ' . $a->orgId . ' AND name = ' . $a->pdo->quote($tagName))->fetchColumn();
        $linked = fn () => (int) $a->pdo->query("SELECT COUNT(*) FROM taggables WHERE tag_id = {$tagId} AND taggable_type = '{$type}' AND taggable_id = {$id}")->fetchColumn();
        if ($linked() !== 1) {
            $wrong[] = "{$type}: tag not attached";
        }
        $a->request('POST', "/records/{$type}/{$id}/tags/{$tagId}/delete");
        if ($linked() !== 0) {
            $wrong[] = "{$type}: tag not removed";
        }

        [$attId, $path] = $attachment($a, $type, $id);
        $dl = $a->request('GET', "/records/{$type}/{$id}/attachments/{$attId}/download");
        if ($dl['status'] !== 200 || $dl['body'] !== 'hello') {
            $wrong[] = "{$type}: download {$dl['status']}";
        }
        $a->request('POST', "/records/{$type}/{$id}/attachments/{$attId}/delete");
        if ($count($a, 'attachments', $attId) !== 0 || is_file($path)) {
            $wrong[] = "{$type}: attachment not deleted";
        }
    }
    LiteAssert::same([], $wrong, implode(' | ', $wrong));
};

$tests['R2. task comment + task attachment delete, ticket attachment delete still work with the right parent'] = function () use ($boot, $f, $note, $attachment, $count) {
    $a = $boot();
    $a->as($a->admin);
    $t = $f->task['A'];
    $a->request('POST', "/tasks/{$t}/comments", [], ['body' => 'LiteTest own comment']);
    $commentId = (int) $a->pdo->query("SELECT id FROM notes WHERE notable_type = 'task' AND notable_id = {$t} AND body = 'LiteTest own comment'")->fetchColumn();
    LiteAssert::true($commentId > 0, 'comment added');
    LiteAssert::same('/tasks/' . $t, $a->request('POST', "/tasks/{$t}/comments/{$commentId}/delete")['location']);
    LiteAssert::same(0, $count($a, 'notes', $commentId));

    [$attId, $path] = $attachment($a, 'task', $t);
    $a->request('POST', "/tasks/{$t}/attachments/{$attId}/delete");
    LiteAssert::same(0, $count($a, 'attachments', $attId));
    LiteAssert::false(is_file($path), 'task file removed');

    [$tAtt, $tPath] = $attachment($a, 'ticket', $f->ticket);
    LiteAssert::same('/support/tickets/' . $f->ticket, $a->request('POST', "/support/tickets/{$f->ticket}/attachments/{$tAtt}/delete")['location']);
    LiteAssert::same(0, $count($a, 'attachments', $tAtt));
    LiteAssert::false(is_file($tPath), 'ticket file removed');
};

$tests['R3. services: own-tag detach still works; ownership-checked deletes accept the right parent only'] = function () use ($boot, $f, $note, $attachment, $count) {
    $a = $boot();
    $a->as($a->admin);
    $tags = $a->container()->make(TagService::class);
    $contact = $f->parents['contact']['B'];
    $tags->attach('contact', $contact, 'LiteTest svc tag', (int) $a->admin['id']);
    $tagId = (int) $a->pdo->query('SELECT id FROM tags WHERE organization_id = ' . $a->orgId . " AND name = 'LiteTest svc tag'")->fetchColumn();
    $tags->detach('contact', $contact, $tagId, (int) $a->admin['id']);
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM taggables WHERE tag_id = {$tagId} AND taggable_id = {$contact}")->fetchColumn(), 'own tag detached');

    $notes = $a->container()->make(NoteService::class);
    $n = $note($a, 'lead', $f->parents['lead']['B']);
    LiteAssert::throws(\App\Core\Exceptions\NotFoundException::class, fn () => $notes->deleteFromSubject('lead', $f->parents['lead']['A'], $n));
    LiteAssert::throws(\App\Core\Exceptions\NotFoundException::class, fn () => $notes->deleteFromSubject('contact', $f->parents['lead']['B'], $n));
    $notes->deleteFromSubject('lead', $f->parents['lead']['B'], $n);
    LiteAssert::same(0, $count($a, 'notes', $n));

    $atts = $a->container()->make(AttachmentService::class);
    [$attId, $path] = $attachment($a, 'company', $f->parents['company']['B']);
    LiteAssert::same(null, $atts->findForSubject('company', $f->parents['company']['A'], $attId));
    LiteAssert::throws(\App\Core\Exceptions\NotFoundException::class, fn () => $atts->deleteFromSubject('company', $f->parents['company']['A'], $attId));
    LiteAssert::true(is_file($path), 'file untouched after a refused delete');
    $atts->deleteFromSubject('company', $f->parents['company']['B'], $attId);
    LiteAssert::false(is_file($path));
};

$tests['rollback leaves no test data behind; suite files removed'] = function () use ($boot, $f) {
    $a = $boot();
    $a->rollBack();
    foreach ($f->files as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM contacts WHERE first_name LIKE 'LiteTest%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM notes WHERE body LIKE 'LiteTest%'")->fetchColumn());
    LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM attachments WHERE stored_path LIKE '%/litetest-%'")->fetchColumn());
    LiteAssert::same([], array_values(array_filter($f->files, 'is_file')));
};

return $tests;
