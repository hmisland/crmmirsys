<?php

declare(strict_types=1);

namespace Modules\Marketing\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\EmailProviderService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Marketing\AudienceRepository;
use Modules\Marketing\MessageRepository;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\Services\CampaignService;
use Modules\Marketing\Services\ContactPrivacy;
use Modules\Marketing\Services\Personalizer;
use Modules\Marketing\Services\QueueWorker;
use Modules\Marketing\Services\SegmentService;
use Modules\Marketing\Services\TemplateService;

use function App\Infrastructure\Theme\t;

/**
 * Marketing → Campaigns: list, new/edit draft (channel, message,
 * audience), review (preview, recipient count, warnings, test send),
 * approve & send, progress, per-recipient results, cancel, retry.
 * Thin: every rule and permission lives in CampaignService.
 */
final class CampaignController extends BaseController
{
    private const WEB_TICK_LIMIT = 25;
    private const WEB_TICK_SECONDS = 8;

    public function __construct(
        TemplateEngine $templates,
        private readonly CampaignService $campaigns,
        private readonly TemplateService $messageTemplates,
        private readonly QueueWorker $worker,
        private readonly AudienceRepository $audience,
        private readonly MessageRepository $messages,
        private readonly EmailProviderService $email,
        private readonly ?SegmentService $segmentService = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'channel' => (string) $request->query('channel', ''),
        ];
        $pagination = Pagination::fromRequest($request, $this->campaigns->count($user, $filters));

        return $this->viewWithLayout('marketing/campaigns/index', 'layout', [
            'campaigns' => $this->campaigns->page($user, $filters, $pagination->perPage, $pagination->offset()),
            'filters' => $filters,
            'pagination' => $pagination,
            'canCreate' => $this->campaigns->availableChannels($user) !== [] && $this->can('marketing.create'),
        ]);
    }

    public function create(Request $request): Response
    {
        $user = CurrentUser::user();
        $channels = $this->campaigns->availableChannels($user);
        $channel = (string) $request->query('channel', $channels[0] ?? 'sms');
        $canSeeContacts = $this->campaigns->canSeeContacts($user);
        $old = ['channel' => $channel, 'message_class' => 'marketing', 'audience_type' => $canSeeContacts ? 'contacts' : 'group'];

        $templateId = (int) $request->query('template', 0);
        if ($templateId > 0) {
            $template = $this->messageTemplates->find($user, $templateId);
            $old = array_merge($old, [
                'channel' => $template['channel'],
                'subject' => $template['subject'],
                'body_text' => $template['body_text'],
                'body_html' => $template['body_html'],
            ]);
        }
        $segmentId = (int) $request->query('segment', 0);
        if ($segmentId > 0) {
            $old['audience_type'] = 'segment';
            $old['segment_id'] = $segmentId;
        }
        $contactId = (int) $request->query('contact', 0);
        if ($contactId > 0 && $canSeeContacts) {
            $old['contact_ids'] = [$contactId];
        }

        return $this->form(null, $old, []);
    }

    public function store(Request $request): Response
    {
        $input = $request->all();
        try {
            $id = $this->campaigns->create(CurrentUser::user(), $input);
        } catch (ValidationException $e) {
            return $this->form(null, $input, $e->errors, 422);
        }

        Session::setFlash('success', t('marketing.flash.draft_saved'));

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    public function edit(Request $request): Response
    {
        $user = CurrentUser::user();
        $campaign = $this->campaigns->find($user, (int) $request->attribute('id'));
        if (!$this->campaigns->canEdit($user, $campaign)) {
            return $this->redirect('/marketing/campaigns/' . $campaign['id']);
        }

        $old = $campaign;
        $ids = $this->campaigns->audienceIds($campaign);
        $old['contact_ids'] = $campaign['audience_type'] === 'contacts' ? $ids : [];
        $old['group_id'] = $campaign['audience_type'] === 'group' ? ($ids[0] ?? 0) : 0;
        $old['tag_id'] = $campaign['audience_type'] === 'tag' ? ($ids[0] ?? 0) : 0;
        $old['segment_id'] = $campaign['audience_type'] === 'segment' ? ($ids[0] ?? 0) : 0;

        return $this->form($campaign, $old, []);
    }

    public function update(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');
        $input = $request->all();
        try {
            $this->campaigns->update($user, $id, $input);
        } catch (ValidationException $e) {
            return $this->form($this->campaigns->find($user, $id), $input, $e->errors, 422);
        }

        Session::setFlash('success', t('marketing.flash.draft_saved'));

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->campaigns->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('marketing.flash.deleted'));

        return $this->redirect('/marketing/campaigns');
    }

    public function show(Request $request, array $errors = [], int $status = 200): Response
    {
        $user = CurrentUser::user();
        $campaign = $this->campaigns->find($user, (int) $request->attribute('id'));
        $isDraft = $campaign['status'] === 'draft';

        $filters = ['campaign_id' => (int) $campaign['id'], 'status' => (string) $request->query('status', ''), 'q' => trim((string) $request->query('q', ''))];
        $pagination = Pagination::fromRequest($request, $isDraft ? 0 : $this->messages->count($filters));

        $canSeeContacts = $this->campaigns->canSeeContacts($user);
        $recipients = $isDraft ? [] : $this->messages->page($filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('marketing/campaigns/show', 'layout', [
            'campaign' => $campaign,
            'review' => $isDraft ? $this->campaigns->review($user, $campaign) : null,
            'counts' => $isDraft ? null : $this->campaigns->counts($user, (int) $campaign['id']),
            'recipients' => $canSeeContacts ? $recipients : ContactPrivacy::maskRows($recipients),
            'canSeeContacts' => $canSeeContacts,
            'filters' => $filters,
            'pagination' => $pagination,
            'canEdit' => $this->campaigns->canEdit($user, $campaign),
            'canSend' => $this->campaigns->canSend($user),
            'canTest' => $this->can('marketing.create'),
            'requiresConsent' => $this->campaigns->requiresConsentConfirmation($campaign),
            'canRetryUnknown' => $this->campaigns->canSend($user) && $this->campaigns->allowsUnknownRetry($campaign) && in_array($campaign['status'], ['sending', 'completed'], true),
            'errors' => $errors,
        ], $status);
    }

    public function send(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $queued = $this->campaigns->approveAndQueue(
                CurrentUser::user(),
                $id,
                (int) $request->input('confirmed_count', -1),
                (string) $request->input('confirm_all', '') === '1',
                (string) $request->input('confirm_consent', '') === '1'
            );
        } catch (ValidationException $e) {
            return $this->show($request, $e->errors, 422);
        }

        Session::setFlash('success', t('marketing.flash.queued', ['count' => (string) $queued]));

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    public function cancel(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $this->campaigns->cancel(CurrentUser::user(), $id);
            Session::setFlash('success', t('marketing.flash.cancelled'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t(array_values($e->errors)[0][0]));
        }

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    public function retry(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $count = $this->campaigns->retryFailed(CurrentUser::user(), $id);
            Session::setFlash('success', t('marketing.flash.retried', ['count' => (string) $count]));
        } catch (ValidationException $e) {
            Session::setFlash('error', t(array_values($e->errors)[0][0]));
        }

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    /** One message with an unknown outcome, queued again after the user confirmed the duplicate risk. */
    public function retryUnknown(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $this->campaigns->retryUnknown(CurrentUser::user(), $id, (int) $request->attribute('message'), (string) $request->input('confirm_duplicate_risk', '') === '1');
            Session::setFlash('success', t('marketing.flash.unknown_retried'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t(array_values($e->errors)[0][0]));
        }

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    public function test(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        try {
            $result = $this->campaigns->testSend(CurrentUser::user(), $id, trim((string) $request->input('address', '')));
            Session::setFlash(
                $result->success ? 'success' : 'error',
                $result->success ? t('marketing.flash.test_sent') : t('marketing.flash.test_failed', ['error' => (string) $result->error])
            );
        } catch (ValidationException $e) {
            Session::setFlash('error', t(array_values($e->errors)[0][0]));
        }

        return $this->redirect('/marketing/campaigns/' . $id);
    }

    /**
     * Progress tick while the campaign page is open: processes a small
     * batch of THIS campaign (the cron worker does the rest) and returns
     * the counts as JSON.
     */
    public function process(Request $request): Response
    {
        $user = CurrentUser::user();
        $campaign = $this->campaigns->find($user, (int) $request->attribute('id'));
        $result = ['processed' => 0, 'remaining' => 0, 'status' => (string) $campaign['status'], 'error' => null];

        if (in_array($campaign['status'], ['queued', 'sending'], true) || ($campaign['status'] === 'preparing' && $campaign['prepare_cursor'] !== null)) {
            $result = $this->worker->processCampaign((int) $campaign['id'], self::WEB_TICK_LIMIT, microtime(true) + self::WEB_TICK_SECONDS);
        } elseif ($campaign['channel'] === 'sms' && $campaign['status'] === 'completed') {
            $this->worker->pollDeliveryReports(200);
        }

        $counts = $this->campaigns->counts($user, (int) $campaign['id']);

        return Response::json(['data' => [
            'status' => $result['status'],
            'remaining' => $result['remaining'],
            'error' => $result['error'] !== null ? t($result['error']) : null,
            'counts' => $counts,
        ]]);
    }

    private function form(?array $campaign, array $old, array $errors, int $status = 200): Response
    {
        $user = CurrentUser::user();
        $channel = (string) ($old['channel'] ?? 'sms');
        // F1: the picker lists contacts only for users who may view contacts.
        $canSeeContacts = $this->campaigns->canSeeContacts($user);
        $contacts = !$canSeeContacts ? [] : array_map(fn ($c) => [
            'id' => (int) $c['id'],
            'name' => trim($c['first_name'] . ' ' . ($c['last_name'] ?? '')) . ' — ' . ($c['phone'] ?: ($c['email'] ?: '#' . $c['id'])),
        ], $this->audience->contactOptions());

        return $this->viewWithLayout('marketing/campaigns/form', 'layout', [
            'campaign' => $campaign,
            'old' => $old,
            'errors' => $errors,
            'channels' => $this->campaigns->availableChannels($user),
            'channel' => $channel,
            'templates' => $this->messageTemplates->all($user, $channel),
            'emailProviders' => $this->email->connectedProviders(),
            'groups' => $this->audience->groups(),
            'tags' => $this->audience->tags(),
            'segments' => $this->segmentService?->options($user) ?? [],
            'contactOptions' => $contacts,
            'canSeeContacts' => $canSeeContacts,
            'canTransactional' => $this->campaigns->hasPermission($user, MarketingPermission::SEND_TRANSACTIONAL),
            'variables' => array_values(array_filter(Personalizer::VARIABLES, fn ($v) => $channel === 'email' || $v !== 'unsubscribe_url')),
        ], $status);
    }

    private function can(string $permission): bool
    {
        return $this->campaigns->hasPermission(CurrentUser::user(), $permission);
    }
}
