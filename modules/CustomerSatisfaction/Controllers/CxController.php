<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerSatisfaction\CxRepository;
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

    public function closeFollowUp(Request $request): Response
    {
        $done = $this->repository->closeFollowUp((int) $request->attribute('id'), (int) CurrentUser::user()['id']);
        Session::setFlash($done ? 'success' : 'error', t($done ? 'cx.follow_up_closed' : 'cx.error.follow_up'));

        return $this->redirect('/cx#follow-ups');
    }
}
