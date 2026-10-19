<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Integrations\IntegrationService;
use App\Infrastructure\Integrations\IntegrationWebhookReceiver;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

/**
 * Organization settings → Integrations (/org/integrations): one page
 * for every registered integration provider (WooCommerce first), each
 * with the organization's connections. Routes need organization.edit;
 * IntegrationService re-checks the provider's module entitlement on
 * every action. Also hosts the public webhook endpoint (no login, no
 * CSRF — authenticated by the provider's signature check).
 */
final class OrgIntegrationsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly IntegrationService $integrations,
        private readonly IntegrationWebhookReceiver $webhooks,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = $this->orgUser();
        $edit = (int) $request->query('edit', 0);

        return $this->viewWithLayout('org/integrations', 'layout', [
            'overview' => $this->integrations->overview($user),
            'editId' => $edit,
            'errors' => (array) (json_decode((string) Session::getFlash('integration_errors'), true) ?? []),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->orgUser();
        $provider = (string) $request->attribute('provider');

        return $this->guard(function () use ($user, $provider, $request) {
            $result = $this->integrations->create($user, $provider, $request->all());
            if ($result['errors'] !== []) {
                return $this->failed($result['errors'], '#new-' . $provider);
            }
            Session::setFlash('success', t('integrations.created'));

            return $this->redirect('/org/integrations#connection-' . $result['id']);
        });
    }

    public function update(Request $request): Response
    {
        $user = $this->orgUser();
        $id = (int) $request->attribute('id');

        return $this->guard(function () use ($user, $id, $request) {
            $errors = $this->integrations->update($user, $id, $request->all());
            if ($errors !== []) {
                return $this->failed($errors, '?edit=' . $id . '#connection-' . $id);
            }
            Session::setFlash('success', t('integrations.saved'));

            return $this->redirect('/org/integrations#connection-' . $id);
        });
    }

    public function setEnabled(Request $request): Response
    {
        $user = $this->orgUser();
        $id = (int) $request->attribute('id');
        $enabled = (string) $request->input('enabled', '') === '1';

        return $this->guard(function () use ($user, $id, $enabled) {
            $this->integrations->setEnabled($user, $id, $enabled);
            Session::setFlash('success', t($enabled ? 'integrations.enabled_flash' : 'integrations.disabled_flash'));

            return $this->redirect('/org/integrations#connection-' . $id);
        });
    }

    public function delete(Request $request): Response
    {
        $user = $this->orgUser();
        $id = (int) $request->attribute('id');

        return $this->guard(function () use ($user, $id) {
            $this->integrations->delete($user, $id);
            Session::setFlash('success', t('integrations.deleted'));

            return $this->redirect('/org/integrations');
        });
    }

    public function test(Request $request): Response
    {
        $user = $this->orgUser();
        $id = (int) $request->attribute('id');

        return $this->guard(function () use ($user, $id) {
            $result = $this->integrations->test($user, $id);
            Session::setFlash($result->ok ? 'success' : 'error', t($result->ok ? 'integrations.test_ok' : (string) $result->errorKey));

            return $this->redirect('/org/integrations#connection-' . $id);
        });
    }

    public function sync(Request $request): Response
    {
        $user = $this->orgUser();
        $id = (int) $request->attribute('id');

        return $this->guard(function () use ($user, $id) {
            $queued = $this->integrations->requestSync($user, $id);
            Session::setFlash('success', t($queued > 0 ? 'integrations.sync_queued' : 'integrations.sync_already_queued'));

            return $this->redirect('/org/integrations#connection-' . $id);
        });
    }

    /** The store's webhook settings: URL + secret, shown only on this page, never cached. */
    public function webhookDetails(Request $request): Response
    {
        $user = $this->orgUser();
        $id = (int) $request->attribute('id');
        try {
            $details = $this->integrations->webhookDetails($user, $id, (string) Config::get('app.url', ''));
        } catch (\DomainException $e) {
            Session::setFlash('error', t($e->getMessage()));

            return $this->redirect('/org/integrations');
        }

        return $this->viewWithLayout('org/integration-webhook', 'layout', ['webhook' => $details, 'connectionId' => $id])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** POST /webhooks/integrations/{key} (public). */
    public function receiveWebhook(Request $request): Response
    {
        $raw = (string) (file_get_contents('php://input', false, null, 0, IntegrationWebhookReceiver::MAX_BODY_BYTES + 1) ?: '');
        $headers = [];
        foreach ((array) $_SERVER as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($name, 5))] = (string) $value;
            }
        }
        $result = $this->webhooks->receive((string) $request->attribute('key'), $headers, $raw);

        return Response::json(['result' => $result['result']], $result['status']);
    }

    /** @param \Closure(): Response $action */
    private function guard(\Closure $action): Response
    {
        try {
            return $action();
        } catch (\DomainException $e) {
            Session::setFlash('error', t($e->getMessage()));

            return $this->redirect('/org/integrations');
        }
    }

    private function failed(array $errors, string $suffix): Response
    {
        Session::setFlash('integration_errors', (string) json_encode($errors));
        Session::setFlash('error', t((string) reset($errors)));

        return $this->redirect('/org/integrations' . $suffix);
    }

    private function orgUser(): array
    {
        $user = CurrentUser::user();
        if ($user === null || (int) ($user['organization_id'] ?? 0) <= 0) {
            throw new NotFoundException();
        }

        return $user;
    }
}
