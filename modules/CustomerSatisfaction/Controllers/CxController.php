<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Application\Services\SmsService;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Messaging\TransactionalDeliveryInterface;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Security\ActionRateLimiter;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerSatisfaction\CxRepository;
use Modules\CustomerSatisfaction\Services\InvitationService;
use Modules\CustomerSatisfaction\Services\SurveyService;

use function App\Infrastructure\Theme\t;

/**
 * /cx — the Customer Satisfaction workspace (minimal by design):
 * surveys, recent invitations, recent responses with open follow-ups.
 * Routes: login + ModuleMiddleware('customer_satisfaction') + cx.*.
 */
final class CxController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly SurveyService $surveys,
        private readonly CxRepository $repository,
        /** @var \Closure(): bool whether a delivery channel provider (Marketing) is installed — resolved lazily */
        private readonly \Closure $deliveryAvailable,
        private readonly ?InvitationService $invitations = null,
        /** @var (\Closure(): ?TransactionalDeliveryInterface)|null the bound deliverer, resolved lazily */
        private readonly ?\Closure $delivery = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = (array) CurrentUser::user();

        return $this->viewWithLayout('cx/index', 'layout', [
            'surveys' => $this->repository->surveys(),
            'invitations' => $this->repository->recentInvitations(20),
            'responses' => $this->repository->recentResponses(20),
            'followUps' => $this->repository->recentResponses(50, true),
            'canManage' => $this->surveys->canManage($user),
            'deliveryAvailable' => ($this->deliveryAvailable)(),
            'test' => $this->surveys->canManage($user) ? $this->testOptions($user) : null,
        ]);
    }

    public function form(Request $request): Response
    {
        $id = $request->attribute('id') !== null ? (int) $request->attribute('id') : null;
        $survey = $id !== null ? $this->surveys->find($id) : [
            'id' => null, 'name' => '', 'type' => 'csat', 'status' => 'inactive', 'trigger_event' => 'order.completed',
            'send_delay_minutes' => 60, 'link_valid_days' => 14, 'channel' => null, 'intro_text' => '', 'message_text' => '',
            'thank_you_text' => '', 'question_label' => '', 'ask_comment' => true, 'comment_label' => '',
        ];
        $old = json_decode((string) Session::getFlash('cx_old'), true);

        return $this->viewWithLayout('cx/form', 'layout', [
            'survey' => is_array($old) ? array_merge($survey, $old, ['ask_comment' => !empty($old['ask_comment'])]) : $survey,
            'errors' => (array) (json_decode((string) Session::getFlash('cx_errors'), true) ?? []),
        ]);
    }

    public function save(Request $request): Response
    {
        $id = $request->attribute('id') !== null ? (int) $request->attribute('id') : null;
        $result = $this->surveys->save((array) CurrentUser::user(), $id, $request->all());
        if ($result['errors'] !== []) {
            Session::setFlash('cx_errors', (string) json_encode($result['errors']));
            Session::setFlash('cx_old', (string) json_encode($request->all()));
            Session::setFlash('error', t((string) reset($result['errors'])));

            return $this->redirect($id !== null ? '/cx/surveys/' . $id . '/edit' : '/cx/surveys/create');
        }
        Session::setFlash('success', t('cx.saved'));

        return $this->redirect('/cx');
    }

    /**
     * Phase E: "Send a test invitation" to the current user's own mobile
     * (profile) or email, through the real invitation flow. cx.manage;
     * at most TEST_PER_HOUR per user.
     */
    public function sendTest(Request $request): Response
    {
        $user = (array) CurrentUser::user();
        if ($this->invitations === null) {
            return $this->redirect('/cx');
        }
        if (!(new ActionRateLimiter())->attempt('cx.test_invitation', [['subject' => 'user:' . (int) $user['id'], 'max' => self::TEST_PER_HOUR, 'window' => 3600]])) {
            Session::setFlash('error', t('cx.test.error.rate_limited'));

            return $this->redirect('/cx#test-invitation');
        }
        [$email, $phone] = self::ownAddresses($user);
        $result = $this->invitations->sendTest(
            (int) $request->input('survey_id', 0),
            (string) $request->input('channel', ''),
            trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            $email,
            $phone,
            Locale::get()?->locale() ?? 'fa',
        );
        Session::setFlash(
            $result['status'] === 'sent' ? 'success' : 'error',
            $result['status'] === 'sent' ? t('cx.test.sent') : t('cx.test.failed', ['reason' => t((string) $result['error'])]),
        );

        return $this->redirect('/cx#test-invitation');
    }

    public const TEST_PER_HOUR = 10;

    /** @return array{channels: string[], email: ?string, phone: ?string} */
    private function testOptions(array $user): array
    {
        $delivery = $this->delivery !== null ? ($this->delivery)() : null;
        $channels = $delivery !== null ? array_values(array_intersect(SurveyService::CHANNELS, $delivery->availableChannels(OrganizationContext::id()))) : [];
        [$email, $phone] = self::ownAddresses($user);

        return ['channels' => $channels, 'email' => $email, 'phone' => $phone];
    }

    /** @return array{0: ?string, 1: ?string} the user's own email and Iranian mobile (from the profile) */
    private static function ownAddresses(array $user): array
    {
        $email = strtolower(trim((string) ($user['email'] ?? '')));

        return [
            filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null,
            SmsService::normalizeNumber((string) ($user['mobile_phone'] ?? '')),
        ];
    }

    public function closeFollowUp(Request $request): Response
    {
        $done = $this->repository->closeFollowUp((int) $request->attribute('id'), (int) CurrentUser::user()['id']);
        Session::setFlash($done ? 'success' : 'error', t($done ? 'cx.follow_up_closed' : 'cx.error.follow_up'));

        return $this->redirect('/cx#follow-ups');
    }
}
