<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Integrations\IntegrationConnection;
use App\Infrastructure\Integrations\IntegrationConnectionRepository;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\WooCommerce\ContactSync\ContactSyncRepository;
use Modules\WooCommerce\ContactSync\ContactSyncSettingsService;
use Modules\WooCommerce\ContactSync\CustomerContactSync;
use Modules\WooCommerce\WooCommerceProvider;

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

    public function __construct(
        TemplateEngine $templates,
        private readonly IntegrationConnectionRepository $connections,
        private readonly ContactSyncSettingsService $settings,
        private readonly ContactSyncRepository $sync,
        private readonly CustomerContactSync $contactSync,
        private readonly ContactCategoryRepository $categories,
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

    /** "Sync contacts now": one bounded batch in this request (the cron task continues the rest). */
    public function run(Request $request): Response
    {
        $connection = $this->connection($request);
        $back = '/org/integrations/' . $connection->id() . '/contact-sync';
        $summary = $this->contactSync->processPending($connection->id(), self::MANUAL_BATCH, microtime(true) + self::MANUAL_SECONDS);
        if ($summary === null) {
            Session::setFlash('error', t('woocommerce.contacts.error.not_enabled'));

            return $this->redirect($back);
        }
        Session::setFlash('success', t('woocommerce.contacts.run_done', array_map('strval', $summary)));

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
