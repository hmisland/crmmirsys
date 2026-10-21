<?php

declare(strict_types=1);

/**
 * Final tasks — follow-up reminder emails (FollowUpReminderService), project
 * delay alerts (ProjectAlertService), both on the shared scheduler, and the
 * seeded Projects / Tasks / Follow-ups help articles. A fake mailer captures
 * the emails. crm_local only, one transaction, rolled back.
 */

require_once __DIR__ . '/Support/LocalApp.php';
require_once __DIR__ . '/Support/ProjectsLite.php';

use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Scheduling\ScheduledTasks;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Followups\FollowUpReminderRepository;
use Modules\Followups\FollowUpRepository;
use Modules\Followups\Services\FollowUpReminderService;
use Modules\Projects\ProjectAlertRepository;
use Modules\Projects\Services\ProjectAlertService;
use Tests\Lite\Support\LocalApp;
use Tests\Lite\Support\ProjectsLite;

$projectsBoot = ProjectsLite::booter();
$boot = function () use ($projectsBoot): LocalApp {
    $a = $projectsBoot();
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('project_alerts', 'follow_up_reminders')")->fetchColumn() !== 2) {
        throw new LiteSkip('project_alerts / follow_up_reminders missing (run the module migrations)');
    }
    if ((int) $a->pdo->query("SELECT COUNT(*) FROM modules WHERE `key` = 'followups'")->fetchColumn() === 0) {
        ProjectsLite::activate($a, 'followups', 'Follow-ups');
    }
    $a->setOrgEntitled('followups', true);
    $a->setOrgEntitled('projects', true);
    // Only this test's rows are due: older local rows are parked as handled.
    $a->pdo->exec("UPDATE follow_up_reminders SET status = 'skipped' WHERE status = 'pending'");
    $a->as($a->admin);

    return $a;
};
$mailer = fn (bool $fail = false): object => new class($fail) implements \App\Infrastructure\Mail\MailerInterface {
    public array $sent = [];

    public function __construct(public bool $fail)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        if ($this->fail) {
            throw new \RuntimeException('LiteTest SMTP unreachable');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'body' => $body];
    }
};
$resolver = fn (): ModuleAccessResolver => new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository());
$reminderService = fn (object $m, bool $ready = true): FollowUpReminderService => new FollowUpReminderService(
    new FollowUpReminderRepository(), new FollowUpRepository(), new UserRepository(), $resolver(), OrganizationTimezone::shared(), $m, fn () => $ready, 'https://crm.test',
);
$alertService = fn (object $m, bool $ready = true): ProjectAlertService => new ProjectAlertService(
    new ProjectAlertRepository(), new UserRepository(), $resolver(), OrganizationTimezone::shared(), $m, fn () => $ready, 'https://crm.test',
);
/** A follow-up assigned to $assignee with one reminder due an hour ago. @return int reminder id */
$dueReminder = function (LocalApp $a, string $title, int $assignee, string $status = 'pending', ?int $org = null): int {
    $org ??= $a->orgId;
    $past = OrganizationTimezone::shared()->now($org)->modify('-1 hour')->format('Y-m-d H:i:s');
    $a->pdo->prepare('INSERT INTO follow_ups (organization_id, title, status, owner_id, assignee_user_id, created_by_user_id, due_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$org, $title, $status, $assignee, $assignee, $assignee, $past]);
    $followUp = (int) $a->pdo->lastInsertId();
    $a->pdo->prepare("INSERT INTO follow_up_reminders (follow_up_id, channel, offset_minutes, scheduled_at) VALUES (?, 'in_app', 30, ?)")->execute([$followUp, $past]);

    return (int) $a->pdo->lastInsertId();
};
$reminderStatus = fn (LocalApp $a, int $id): string => (string) $a->pdo->query("SELECT status FROM follow_up_reminders WHERE id = {$id}")->fetchColumn();
$project = function (LocalApp $a, string $name, string $status, ?string $end, ?int $owner = null): int {
    $owner ??= (int) $a->admin['id'];
    $a->pdo->prepare('INSERT INTO projects (organization_id, code, name, status, owner_id, expected_end_date, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$a->orgId, 'RA-' . bin2hex(random_bytes(4)), $name, $status, $owner, $end, $owner]);

    return (int) $a->pdo->lastInsertId();
};
$process = fn (LocalApp $a, ProjectAlertService $s, string $today): array => OrganizationContext::runAs($a->orgId, fn () => $s->processCurrentOrganization($today));

return [
    'scheduler: both new tasks are registered on the shared scheduler' => function () use ($boot) {
        $a = $boot();
        $names = $a->container()->make(ScheduledTasks::class)->names();
        LiteAssert::true(in_array('followups.reminders', $names, true), implode(',', $names));
        LiteAssert::true(in_array('projects.delay_alerts', $names, true), implode(',', $names));
    },

    'follow-up reminders: one email to the assignee in their language, sent once even when run again' => function () use ($boot, $mailer, $reminderService, $dueReminder, $reminderStatus) {
        $a = $boot();
        $a->pdo->prepare("UPDATE users SET language = 'fa' WHERE id = ?")->execute([(int) $a->member['id']]);
        $id = $dueReminder($a, 'LiteTest RA call back', (int) $a->member['id']);
        $m = $mailer();
        $first = $reminderService($m)->processDue(30);
        LiteAssert::same(1, $first['sent']);
        LiteAssert::same('sent', $reminderStatus($a, $id));
        LiteAssert::same((string) $a->member['email'], $m->sent[0]['to']);
        LiteAssert::contains('LiteTest RA call back', $m->sent[0]['subject']);
        LiteAssert::contains('یادآوری پیگیری', $m->sent[0]['subject'], 'recipient language (fa)');
        LiteAssert::contains('https://crm.test/followups/', $m->sent[0]['body']);
        $reminderService($m)->processDue(30);
        LiteAssert::same(1, count($m->sent), 'never twice');
    },

    'follow-up reminders: closed follow-ups and people without the module are skipped; failures marked; nothing claimed while mail is not configured' => function () use ($boot, $mailer, $reminderService, $dueReminder, $reminderStatus) {
        $a = $boot();
        $done = $dueReminder($a, 'LiteTest RA done', (int) $a->admin['id'], 'completed');
        $cancelled = $dueReminder($a, 'LiteTest RA cancelled', (int) $a->admin['id'], 'cancelled');
        $m = $mailer();
        $reminderService($m)->processDue(30);
        LiteAssert::same(['skipped', 'skipped'], [$reminderStatus($a, $done), $reminderStatus($a, $cancelled)]);
        LiteAssert::same([], $m->sent);

        $a->setUserOverride((int) $a->member['id'], 'followups', false);
        $noModule = $dueReminder($a, 'LiteTest RA no module', (int) $a->member['id']);
        $reminderService($m)->processDue(30);
        LiteAssert::same('skipped', $reminderStatus($a, $noModule));
        $a->setUserOverride((int) $a->member['id'], 'followups', null);

        $waiting = $dueReminder($a, 'LiteTest RA waiting', (int) $a->admin['id']);
        $w = $reminderService($m, false)->processDue(30);
        LiteAssert::true($w['waiting'] >= 1);
        LiteAssert::same('pending', $reminderStatus($a, $waiting), 'stays pending until mail works');

        $failing = $mailer(true);
        $reminderService($failing)->processDue(30);
        LiteAssert::same('failed', $reminderStatus($a, $waiting));
    },

    'follow-up reminders: another organization\'s reminder is handled in its own organization, never sent to this one\'s users' => function () use ($boot, $mailer, $reminderService, $dueReminder, $reminderStatus) {
        $a = $boot();
        if ($a->otherOrgId === null) {
            throw new LiteSkip('needs a second organization');
        }
        // A foreign follow-up wrongly pointing at this organization's member: never delivered.
        $id = $dueReminder($a, 'LiteTest RA foreign', (int) $a->member['id'], 'pending', $a->otherOrgId);
        $m = $mailer();
        $reminderService($m)->processDue(30);
        LiteAssert::same('skipped', $reminderStatus($a, $id));
        LiteAssert::same([], array_filter($m->sent, fn ($e) => str_contains($e['subject'], 'LiteTest RA foreign')));
    },

    'delay alerts: one email per manager with overdue projects and late milestones; never for closed projects; not repeated' => function () use ($boot, $mailer, $alertService, $project, $process) {
        $a = $boot();
        $late = $project($a, 'LiteTest RA late project', 'active', '2026-06-01');
        $project($a, 'LiteTest RA done project', 'completed', '2026-06-01');
        $project($a, 'LiteTest RA cancelled project', 'cancelled', '2026-06-01');
        $project($a, 'LiteTest RA future project', 'active', '2026-12-01');
        $withMilestone = $project($a, 'LiteTest RA milestone project', 'planned', null);
        $a->pdo->prepare("INSERT INTO project_milestones (organization_id, project_id, name, date, status) VALUES (?, ?, 'LiteTest RA late milestone', '2026-06-05', 'planned'), (?, ?, 'LiteTest RA done milestone', '2026-06-05', 'completed')")
            ->execute([$a->orgId, $withMilestone, $a->orgId, $withMilestone]);

        $m = $mailer();
        $r = $process($a, $alertService($m), '2026-06-15');
        $mine = array_values(array_filter($m->sent, fn ($e) => str_contains($e['body'], 'LiteTest RA')));
        LiteAssert::same(1, count($mine), 'one email for the manager');
        LiteAssert::same((string) $a->admin['email'], $mine[0]['to']);
        LiteAssert::contains('LiteTest RA late project', $mine[0]['body']);
        LiteAssert::contains('LiteTest RA late milestone', $mine[0]['body']);
        foreach (['done project', 'cancelled project', 'future project', 'done milestone'] as $absent) {
            LiteAssert::notContains('LiteTest RA ' . $absent, $mine[0]['body']);
        }
        LiteAssert::contains("https://crm.test/projects/{$late}", $mine[0]['body']);
        LiteAssert::true($r['items'] >= 2);

        $again = $mailer();
        $process($a, $alertService($again), '2026-06-16');
        LiteAssert::same([], array_filter($again->sent, fn ($e) => str_contains($e['body'], 'LiteTest RA')), 'not repeated');

        // Rescheduled and missed again: a new alert.
        $a->pdo->exec("UPDATE projects SET expected_end_date = '2026-06-20' WHERE id = {$late}");
        $process($a, $alertService($again), '2026-06-25');
        LiteAssert::same(1, count(array_filter($again->sent, fn ($e) => str_contains($e['body'], 'LiteTest RA late project'))));
    },

    'delay alerts: manager without Projects access is skipped (recorded, no email); nothing claimed while mail is not configured' => function () use ($boot, $mailer, $alertService, $project, $process) {
        $a = $boot();
        $p = $project($a, 'LiteTest RA member-managed', 'active', '2026-06-01', (int) $a->member['id']);
        $m = $mailer();
        $w = $alertService($m, false)->run(10);
        LiteAssert::true($w['waiting']);
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM project_alerts WHERE project_id = {$p}")->fetchColumn());

        $a->setUserOverride((int) $a->member['id'], 'projects', false);
        $process($a, $alertService($m), '2026-06-15');
        LiteAssert::same('skipped', $a->pdo->query("SELECT status FROM project_alerts WHERE project_id = {$p}")->fetchColumn());
        LiteAssert::same([], array_filter($m->sent, fn ($e) => str_contains($e['body'], 'LiteTest RA member-managed')));
        $a->setUserOverride((int) $a->member['id'], 'projects', null);
    },

    'help: the five new guides are seeded in five languages, filed by category and linked from the pages' => function () use ($boot) {
        $a = $boot();
        $slugs = ['projects-getting-started', 'projects-milestones-tasks', 'projects-dashboard-delays', 'tasks-getting-started', 'followups-getting-started'];
        foreach ($slugs as $slug) {
            $n = (int) $a->pdo->query("SELECT COUNT(*) FROM help_article_translations t JOIN help_articles h ON h.id = t.article_id WHERE h.slug = " . $a->pdo->quote($slug))->fetchColumn();
            LiteAssert::same(5, $n, $slug);
            $root = dirname(__DIR__, 2) . '/database/help-content/' . $slug;
            foreach (['en', 'fa', 'ar', 'tr', 'fr'] as $locale) {
                LiteAssert::true(is_file("{$root}/{$locale}.html") && filesize("{$root}/{$locale}.html") > 500, "{$slug}/{$locale}.html");
            }
        }
        $cats = $a->pdo->query("SELECT h.slug, c.category FROM help_article_categories c JOIN help_articles h ON h.id = c.article_id WHERE h.slug IN ('projects-getting-started', 'tasks-getting-started', 'followups-getting-started') ORDER BY h.slug")->fetchAll(\PDO::FETCH_KEY_PAIR);
        LiteAssert::same(['followups-getting-started' => 'followups', 'projects-getting-started' => 'projects', 'tasks-getting-started' => 'sales'], $cats);
        LiteAssert::contains('/help/projects-getting-started', $a->request('GET', '/projects')['body']);
        LiteAssert::contains('/help/projects-dashboard-delays', $a->request('GET', '/projects/dashboard')['body']);
        LiteAssert::same(200, $a->request('GET', '/help/projects-dashboard-delays')['status']);
    },

    'translations: the new email keys exist in all five languages' => function () {
        $root = dirname(__DIR__, 2);
        $en = require $root . '/lang/en.php';
        $new = array_filter(array_keys($en), fn (string $k) => str_starts_with($k, 'followups.email.') || str_starts_with($k, 'projects.alert.'));
        LiteAssert::true(count($new) >= 12, 'keys: ' . count($new));
        foreach (['fa', 'ar', 'tr', 'fr'] as $locale) {
            $lang = require $root . "/lang/{$locale}.php";
            foreach ($new as $key) {
                LiteAssert::true(isset($lang[$key]) && $lang[$key] !== '', "{$locale}: {$key}");
            }
        }
    },

    'rollback leaves no test data behind' => function () use ($boot) {
        $a = $boot();
        $a->rollBack();
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM follow_ups WHERE title LIKE 'LiteTest RA%'")->fetchColumn());
        LiteAssert::same(0, (int) $a->pdo->query("SELECT COUNT(*) FROM projects WHERE name LIKE 'LiteTest RA%'")->fetchColumn());
    },
];
