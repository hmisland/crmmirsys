<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use App\Infrastructure\Config\Config;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Mail\MailerInterface;
use App\Infrastructure\Mail\MailReadiness;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\UserRepository;
use App\Infrastructure\Time\OrganizationTimezone;
use Modules\Projects\ProjectAlertRepository;

/**
 * Delay alerts by email — run by the shared scheduler (app/bin/scheduler.php,
 * task "projects.delay_alerts"). When an open project passes its expected end
 * date, or a planned milestone of an open project passes its date, the
 * project manager (projects.owner_id) gets ONE email listing their newly
 * late items, in their language, through the CRM's mailer — the same
 * semantics as the Projects dashboard (completed / cancelled projects are
 * never late; a milestone is late while still "planned").
 *
 * Each item is alerted once per date (project_alerts; a rescheduled item
 * alerts again when it is late again). Managers who are inactive or no
 * longer have the Projects module are recorded as skipped. While mail is not
 * configured nothing is claimed, so alerts go out once it is.
 */
final class ProjectAlertService
{
    public const LIMIT_PER_ORGANIZATION = 200;

    public function __construct(
        private readonly ProjectAlertRepository $alerts,
        private readonly UserRepository $users,
        private readonly ModuleAccessResolver $modules,
        private readonly OrganizationTimezone $clock,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?\Closure $mailReady = null,
        private readonly string $appUrl = '',
        private readonly string $langPath = '',
    ) {
    }

    /** @return array{emails: int, items: int, skipped: int, failed: int, waiting: bool} */
    public function run(int $budgetSeconds = 50): array
    {
        $result = ['emails' => 0, 'items' => 0, 'skipped' => 0, 'failed' => 0, 'waiting' => false];
        if ($this->mailer === null || !($this->mailReady !== null ? (bool) ($this->mailReady)() : MailReadiness::isConfigured())) {
            $result['waiting'] = true;

            return $result;
        }

        // No time zone is more than 14 h ahead of UTC: every organization's "today" is before this.
        $latest = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+14 hours')->format('Y-m-d');
        $deadline = time() + max(1, $budgetSeconds);
        foreach ($this->alerts->organizationsWithCandidates($latest) as $organizationId) {
            if (time() >= $deadline) {
                break;
            }
            $r = OrganizationContext::runAs($organizationId, fn (): array => $this->processCurrentOrganization());
            foreach (['emails', 'items', 'skipped', 'failed'] as $k) {
                $result[$k] += $r[$k];
            }
        }

        return $result;
    }

    /** @return array{emails: int, items: int, skipped: int, failed: int} */
    public function processCurrentOrganization(?string $today = null): array
    {
        $organizationId = OrganizationContext::id();
        $today ??= $this->clock->today($organizationId);
        $result = ['emails' => 0, 'items' => 0, 'skipped' => 0, 'failed' => 0];

        $byOwner = [];
        foreach ($this->alerts->pending($today, self::LIMIT_PER_ORGANIZATION) as $item) {
            $byOwner[$item['owner_id']][] = $item;
        }

        foreach ($byOwner as $ownerId => $items) {
            $user = $this->users->findById((int) $ownerId);
            $eligible = $user !== null
                && (int) ($user['organization_id'] ?? 0) === $organizationId
                && ($user['status'] ?? '') === 'active'
                && $this->modules->resolvedForUser('projects', $user)
                && filter_var(trim((string) ($user['email'] ?? '')), FILTER_VALIDATE_EMAIL) !== false;

            $claimed = [];
            foreach ($items as $item) {
                if ($this->alerts->claim($item, $user !== null ? (int) $ownerId : null, $eligible ? 'sent' : 'skipped')) {
                    $claimed[] = $item;
                }
            }
            if ($claimed === []) {
                continue; // another run has them
            }
            if (!$eligible) {
                $result['skipped'] += count($claimed);
                continue;
            }

            [$subject, $body] = $this->compose($user, $claimed, $today);
            try {
                $this->mailer->send(trim((string) $user['email']), $subject, $body);
                $result['emails']++;
                $result['items'] += count($claimed);
            } catch (\Throwable) {
                foreach ($claimed as $item) {
                    $this->alerts->markFailed($item);
                }
                $result['failed'] += count($claimed);
            }
        }

        return $result;
    }

    /** @return array{0: string, 1: string} plain text in the manager's language */
    private function compose(array $user, array $items, string $today): array
    {
        $locale = in_array($user['language'] ?? null, Translator::SUPPORTED_LOCALES, true) ? (string) $user['language'] : 'en';
        $tr = new Translator($locale, $this->langPath !== '' ? $this->langPath : dirname(__DIR__, 3) . '/lang');
        $fmt = new LocaleFormatter($locale);
        $base = rtrim($this->appUrl !== '' ? $this->appUrl : (string) Config::get('app.url', ''), '/');

        $lines = [$tr->get('projects.alert.greeting', ['name' => trim((string) ($user['first_name'] ?? ''))]), '', $tr->get('projects.alert.intro'), ''];
        foreach ($items as $item) {
            $days = $fmt->number((string) ProjectDashboardService::daysBetween((string) $item['due_date'], $today));
            $lines[] = '• ' . ($item['kind'] === 'project_overdue'
                ? $tr->get('projects.alert.project_line', ['project' => $item['code'] . ' ' . $item['project_name'], 'date' => $fmt->date((string) $item['due_date']), 'days' => $days])
                : $tr->get('projects.alert.milestone_line', ['milestone' => $item['name'], 'project' => $item['code'] . ' ' . $item['project_name'], 'date' => $fmt->date((string) $item['due_date']), 'days' => $days]))
                . ' — ' . $base . '/projects/' . (int) $item['project_id'];
        }
        $lines[] = '';
        $lines[] = $tr->get('projects.alert.open') . ': ' . $base . '/projects/dashboard';

        return [$tr->get('projects.alert.subject', ['count' => $fmt->number((string) count($items))]), implode("\n", $lines)];
    }
}
