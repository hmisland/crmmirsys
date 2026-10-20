<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Integrations\IntegrationConnection;
use App\Infrastructure\Integrations\IntegrationConnectionRepository;
use App\Infrastructure\Integrations\IntegrationJobRepository;
use App\Infrastructure\Integrations\IntegrationService;
use App\Infrastructure\Integrations\IntegrationWorker;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\WooCommerce\ContactSync\ContactSyncRepository;
use Modules\WooCommerce\ContactSync\ContactSyncSettingsService;
use Modules\WooCommerce\ContactSync\CustomerContactSync;
use Modules\WooCommerce\WooCommerceProvider;
use Modules\WooCommerce\WooDataRepository;

use function App\Infrastructure\Theme\t;

/**
 * Organization settings → Integrations → a WooCommerce shop → Contact
 * sync (/org/integrations/{id}/contact-sync). Routes: login +
 * organization.edit + the woocommerce module; the connection must be a
 * WooCommerce shop of the current organization.
 */
final class ContactSyncController extends BaseController
{
    private const MANUAL_BATCH = 300;
    private const MANUAL_SECONDS = 20;
    /** Time for fetching the shop's latest orders before the contacts are synced. */
    private const IMPORT_SECONDS = 15;

    public function __construct(
        TemplateEngine $templates,
        private readonly IntegrationConnectionRepository $connections,
        private readonly ContactSyncSettingsService $settings,
        private readonly ContactSyncRepository $sync,
        private readonly CustomerContactSync $contactSync,
        private readonly ContactCategoryRepository $categories,
        private readonly ?IntegrationService $integrations = null,
        private readonly ?IntegrationWorker $worker = null,
        private readonly ?IntegrationJobRepository $jobs = null,
    ) {
        parent::__construct($templates);
    }

    public function show(Request $request): Response
    {
        $connection = $this->connection($request);
        $form = $this->settings->forForm($connection->id(), (string) $connection->config('store_url', ''));
        $old = json_decode((string) Session::getFlash('woo_contact_sync_old'), true);
        if (is_array($old)) {
            $form = array_merge($form, [
                'enabled' => (string) ($old['enabled'] ?? '') === '1',
                'category_id' => $old['category_id'] ?? $form['category_id'],
                'tags' => (string) ($old['tags'] ?? ''),
                'source' => (string) ($old['source'] ?? ''),
                'mapping' => is_array($old['map'] ?? null) ? array_merge($form['mapping'], $old['map']) : $form['mapping'],
                'new_category' => (string) ($old['new_category'] ?? ''),
            ]);
        }

        return $this->viewWithLayout('woocommerce/contact-sync', 'layout', [
            'connection' => $connection->toView(),
            'form' => $form,
            'categories' => $this->categories->all(),
            'counts' => $this->sync->statusCounts($connection->id()),
            'shopData' => (new WooDataRepository())->counts($connection->id()) + [
                'oldest_order' => (new WooDataRepository())->oldestOrderAt($connection->id()),
                // The order import's current position (orders changed after this moment are fetched next), UTC.
                'import_position' => self::position($connection->syncState()['orders']['after'] ?? null),
            ],
            'jobs' => $this->jobs?->countsForConnection($connection->id(), $connection->organizationId()) ?? ['pending' => 0, 'running' => 0, 'failed' => 0],
            'problems' => $this->sync->problems($connection->id()),
            'errors' => (array) (json_decode((string) Session::getFlash('woo_contact_sync_errors'), true) ?? []),
        ]);
    }

    public function save(Request $request): Response
    {
        $connection = $this->connection($request);
        $input = $request->all();
        $errors = $this->settings->save((array) CurrentUser::user(), $connection->id(), $input, (string) $connection->config('store_url', ''));
        $back = '/org/integrations/' . $connection->id() . '/contact-sync';
        if ($errors !== []) {
            Session::setFlash('woo_contact_sync_errors', (string) json_encode($errors));
            Session::setFlash('woo_contact_sync_old', (string) json_encode(array_intersect_key($input, array_flip(['enabled', 'category_id', 'new_category', 'tags', 'source', 'map']))));
            Session::setFlash('error', t((string) reset($errors)));

            return $this->redirect($back);
        }
        Session::setFlash('success', t('woocommerce.contacts.saved'));

        return $this->redirect($back);
    }

    /**
     * "Sync contacts now": first fetches the shop's latest orders (queues the
     * usual order sync and runs this shop's queued jobs now, within a time
     * limit — the same jobs cron runs, so nothing is processed twice), then
     * syncs one bounded batch of customers. The cron task continues the rest.
     */
    public function run(Request $request): Response
    {
        return $this->importAndSync($this->connection($request));
    }

    /**
     * "Import older orders": moves the shop's order-sync starting point back
     * to a date (or the whole history), then imports and syncs like "Sync
     * contacts now"; cron continues with the rest. Re-reading an order is
     * harmless (one row per order), and an order completed long ago never
     * starts a satisfaction survey (only orders completed in the last days do).
     */
    public function importHistory(Request $request): Response
    {
        $connection = $this->connection($request);
        $back = '/org/integrations/' . $connection->id() . '/contact-sync';
        if ((string) $request->input('all_history', '') === '1') {
            $after = self::ALL_HISTORY;
        } else {
            $date = trim((string) $request->input('since', ''));
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || $date >= gmdate('Y-m-d')) {
                Session::setFlash('error', t('woocommerce.contacts.history.error_date'));

                return $this->redirect($back);
            }
            $after = $date . 'T00:00:00';
        }
        $this->connections->mergeSyncState($connection, ['orders' => ['after' => $after, 'page' => 1]]);

        return $this->importAndSync($connection, t('woocommerce.contacts.history.started') . ' ');
    }

    /** The sync cursor (Y-m-dTH:i:s, UTC) as a stored-style time; null when not started or "from the beginning". */
    private static function position(mixed $after): ?string
    {
        return is_string($after) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $after) === 1 && $after !== self::ALL_HISTORY
            ? str_replace('T', ' ', $after)
            : null;
    }

    /** WooCommerce's modified_after for "from the beginning". */
    public const ALL_HISTORY = '2000-01-01T00:00:00';

    private function importAndSync(IntegrationConnection $connection, string $prefix = ''): Response
    {
        $back = '/org/integrations/' . $connection->id() . '/contact-sync';
        $user = (array) CurrentUser::user();

        $import = null;
        if ($this->integrations !== null && $this->worker !== null) {
            try {
                $this->integrations->requestSync($user, $connection->id());
                // A sync waiting to retry after a failure is tried again now: the user asked for it.
                $this->jobs?->makeSyncDueNow($connection->id(), $connection->organizationId());
                $import = $this->worker->runForConnection($connection->id(), $connection->organizationId(), self::IMPORT_SECONDS);
            } catch (\DomainException $e) {
                Session::setFlash('error', t($e->getMessage()));

                return $this->redirect($back);
            }
        }
        $importError = $this->connections->find($connection->id())?->toView()['last_error'] ?? null;

        $summary = $this->contactSync->processPending($connection->id(), self::MANUAL_BATCH, microtime(true) + self::MANUAL_SECONDS);
        if ($summary === null) {
            Session::setFlash('error', t('woocommerce.contacts.error.not_enabled'));

            return $this->redirect($back);
        }
        $messages = [];
        if ($import !== null) {
            $messages[] = t('woocommerce.contacts.import_done', ['jobs' => (string) $import['processed'], 'remaining' => (string) $import['remaining']]);
        }
        $handled = array_sum(array_intersect_key($summary, array_flip(CustomerContactSync::STATUSES)));
        $messages[] = $handled > 0
            ? t('woocommerce.contacts.run_done', array_map('strval', $summary))
            : t('woocommerce.contacts.run_nothing', ['customers' => (string) ((new WooDataRepository())->counts($connection->id())['customers'])]);
        if ($importError !== null && $importError !== '') {
            $messages[] = t('woocommerce.contacts.import_error', ['error' => t((string) $importError)]);
        }
        Session::setFlash($importError !== null && $importError !== '' && $handled === 0 ? 'error' : 'success', $prefix . implode(' ', $messages));

        return $this->redirect($back);
    }

    private function connection(Request $request): IntegrationConnection
    {
        $user = CurrentUser::user();
        if ($user === null || (int) ($user['organization_id'] ?? 0) <= 0) {
            throw new NotFoundException();
        }
        $connection = $this->connections->find((int) $request->attribute('id'));
        if ($connection === null || $connection->provider() !== WooCommerceProvider::KEY) {
            throw new NotFoundException();
        }

        return $connection;
    }
}
