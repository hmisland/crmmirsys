<?php

declare(strict_types=1);

namespace Modules\Followups\Controllers;

use App\Application\Controllers\BaseController;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Followups\Services\FollowUpRuleService;

use function App\Infrastructure\Theme\t;

final class FollowUpRuleController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly FollowUpRuleService $rules,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        return $this->viewWithLayout('followups/rules/index', 'layout', [
            'rules' => $this->rules->list(CurrentUser::user()),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $this->rules->create(CurrentUser::user(), $request->all());
            Session::setFlash('success', t('followups.flash.rule_created'));
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? t('followups.flash.rule_create_failed'));
        }

        return $this->redirect('/followups/rules');
    }

    public function toggle(Request $request): Response
    {
        $this->rules->toggle(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('followups.flash.rule_updated'));

        return $this->redirect('/followups/rules');
    }

    public function delete(Request $request): Response
    {
        $this->rules->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('followups.flash.rule_deleted'));

        return $this->redirect('/followups/rules');
    }
}
