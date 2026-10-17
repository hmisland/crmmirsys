<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Modules\ModuleGate;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\MarketingBale\Channels\BaleChannel;
use Modules\MarketingBale\Services\BaleSettingsService;

use function App\Infrastructure\Theme\t;

/**
 * Organization settings → Messaging providers → Bale (Safir).
 * Routes need organization.edit (RbacMiddleware); every POST here also
 * requires the organization to hold BOTH "marketing" and
 * "bale_messaging" — refused server-side otherwise, whatever the page
 * showed. Saving never contacts Safir and never sends a message.
 */
final class BaleSettingsController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly BaleSettingsService $settings,
        private readonly ModuleGate $gate,
    ) {
        parent::__construct($templates);
    }

    /** The card on /org/messaging (rendered into the core page). */
    public function card(array $user): string
    {
        return $this->templates->render('org/_bale_card', [
            'entitled' => $this->entitled($user),
            'bale' => $this->settings->view(),
            'maxText' => BaleChannel::MAX_TEXT_LENGTH,
        ]);
    }

    public function save(Request $request): Response
    {
        if (!$this->allowed()) {
            return $this->back();
        }
        $error = $this->settings->save(
            (string) $request->input('bot_id', ''),
            (string) $request->input('api_key', ''),
            (int) CurrentUser::user()['id']
        );
        Session::setFlash($error === null ? 'success' : 'error', t($error ?? 'bale.saved'));

        return $this->back();
    }

    public function disconnect(Request $request): Response
    {
        if ($this->allowed()) {
            $this->settings->disconnect((int) CurrentUser::user()['id']);
            Session::setFlash('success', t('bale.disconnected'));
        }

        return $this->back();
    }

    public function setEnabled(Request $request): Response
    {
        if ($this->allowed()) {
            $enabled = (string) $request->input('enabled', '') === '1';
            $this->settings->setEnabled($enabled, (int) CurrentUser::user()['id']);
            Session::setFlash('success', t($enabled ? 'bale.enabled' : 'bale.disabled_flash'));
        }

        return $this->back();
    }

    private function entitled(array $user): bool
    {
        return $this->gate->allows($user, 'marketing', null) && $this->gate->allows($user, BaleChannel::MODULE_KEY, null);
    }

    /** Organization user + both modules; a Super Admin without an organization gets a 404. */
    private function allowed(): bool
    {
        $user = CurrentUser::user();
        if ((int) ($user['organization_id'] ?? 0) <= 0) {
            throw new NotFoundException();
        }
        if (!$this->entitled((array) $user)) {
            Session::setFlash('error', t('bale.module_unavailable'));

            return false;
        }

        return true;
    }

    private function back(): Response
    {
        return $this->redirect('/org/messaging#provider-bale');
    }
}
