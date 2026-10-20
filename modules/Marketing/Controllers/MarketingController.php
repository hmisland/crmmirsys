<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\ContactPrivacy;
use Modules\Marketing\Services\InsightsService;
use Modules\Marketing\Services\SuppressionService;
use Modules\Marketing\Services\TemplateService;
use Modules\Marketing\Services\TransactionalHistory;

use function App\Infrastructure\Theme\t;

/** Marketing dashboard, message history, opt-outs and templates. */
final class MarketingController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly InsightsService $insights,
        private readonly CampaignService $campaigns,
        private readonly SuppressionService $suppressions,
        private readonly TemplateService $messageTemplates,
        private readonly ?TransactionalHistory $transactional = null,
    ) {
        parent::__construct($templates);
    }

    public function dashboard(Request $request): Response
    {
        $user = CurrentUser::user();

        return $this->viewWithLayout('marketing/dashboard', 'layout', $this->insights->dashboard($user) + [
            'canCreate' => $this->campaigns->hasPermission($user, 'marketing.create') && $this->campaigns->availableChannels($user) !== [],
            'channels' => $this->campaigns->availableChannels($user),
            'canConfigure' => !empty(CurrentUser::user()['organization_id']),
        ]);
    }

    public function history(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'channel' => (string) $request->query('channel', ''),
        ];
        $pagination = Pagination::fromRequest($request, $this->insights->historyCount($user, $filters));

        $messages = $this->insights->history($user, $filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('marketing/history', 'layout', [
            'messages' => $this->campaigns->canSeeContacts($user) ? $messages : ContactPrivacy::maskRows($messages),
            'filters' => $filters,
            'pagination' => $pagination,
        ]);
    }

    /** Phase B: transactional messages (sent by other modules through Marketing). */
    public function transactional(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'channel' => (string) $request->query('channel', ''),
            'purpose' => (string) $request->query('purpose', ''),
        ];
        $history = $this->transactional ?? throw new \App\Core\Exceptions\NotFoundException();
        $pagination = Pagination::fromRequest($request, $history->count($user, $filters));
        $rows = $history->page($user, $filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('marketing/transactional', 'layout', [
            'rows' => $this->campaigns->canSeeContacts($user) ? $rows : ContactPrivacy::maskRows($rows),
            'purposes' => $history->purposes($user),
            'filters' => $filters,
            'pagination' => $pagination,
        ]);
    }

    // --- Opt-outs ------------------------------------------------------------

    public function suppressions(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = ['q' => trim((string) $request->query('q', '')), 'channel' => (string) $request->query('channel', '')];
        $pagination = Pagination::fromRequest($request, $this->suppressions->count($user, $filters));

        $rows = $this->suppressions->page($user, $filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('marketing/suppressions', 'layout', [
            'rows' => $this->campaigns->canSeeContacts($user) ? $rows : ContactPrivacy::maskRows($rows),
            'filters' => $filters,
            'pagination' => $pagination,
            'canManage' => $this->campaigns->hasPermission($user, 'marketing.manage_suppressions'),
        ]);
    }

    public function addSuppression(Request $request): Response
    {
        try {
            $this->suppressions->addManual(CurrentUser::user(), (string) $request->input('channel', ''), (string) $request->input('address', ''));
            Session::setFlash('success', t('marketing.flash.suppression_added'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t(array_values($e->errors)[0][0]));
        }

        return $this->redirect('/marketing/suppressions');
    }

    public function removeSuppression(Request $request): Response
    {
        $this->suppressions->remove(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('marketing.flash.suppression_removed'));

        return $this->redirect('/marketing/suppressions');
    }

    // --- Templates -----------------------------------------------------------

    public function templates(Request $request): Response
    {
        $user = CurrentUser::user();

        return $this->viewWithLayout('marketing/templates/index', 'layout', [
            'templates' => $this->messageTemplates->all($user),
            'canManage' => $this->campaigns->hasPermission($user, 'marketing.manage_templates'),
            'canCreateCampaign' => $this->campaigns->hasPermission($user, 'marketing.create'),
        ]);
    }

    public function templateForm(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        $template = $id > 0 ? $this->messageTemplates->find(CurrentUser::user(), $id) : null;
        $old = $template ?? ['channel' => (string) $request->query('channel', 'sms')];

        return $this->templateView($template, $old, []);
    }

    public function saveTemplate(Request $request): Response
    {
        $id = (int) $request->attribute('id', 0);
        $input = $request->all();
        try {
            $this->messageTemplates->save(CurrentUser::user(), $id > 0 ? $id : null, $input);
        } catch (ValidationException $e) {
            $template = $id > 0 ? $this->messageTemplates->find(CurrentUser::user(), $id) : null;

            return $this->templateView($template, $input, $e->errors, 422);
        }

        Session::setFlash('success', t('marketing.flash.template_saved'));

        return $this->redirect('/marketing/templates');
    }

    public function deleteTemplate(Request $request): Response
    {
        $this->messageTemplates->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('marketing.flash.template_deleted'));

        return $this->redirect('/marketing/templates');
    }

    private function templateView(?array $template, array $old, array $errors, int $status = 200): Response
    {
        return $this->viewWithLayout('marketing/templates/form', 'layout', [
            'template' => $template,
            'old' => $old,
            'errors' => $errors,
        ], $status);
    }
}
