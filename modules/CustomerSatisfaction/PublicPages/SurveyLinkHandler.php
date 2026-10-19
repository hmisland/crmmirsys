<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\PublicPages;

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\PublicLinks\PublicLink;
use App\Infrastructure\PublicLinks\PublicLinkHandlerInterface;
use App\Infrastructure\PublicLinks\PublicLinkService;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\CustomerSatisfaction\CustomerSatisfactionModule;
use Modules\CustomerSatisfaction\Services\InvitationService;
use Modules\CustomerSatisfaction\Services\ResponseService;

/**
 * The public survey page behind /p/{token} (purpose "cx_survey").
 * Shows only the organization's name, the survey's texts and its
 * questions — never order data, ids or contact details.
 */
final class SurveyLinkHandler implements PublicLinkHandlerInterface
{
    public function __construct(
        private readonly TemplateEngine $templates,
        private readonly ResponseService $responses,
        private readonly PublicLinkService $links,
    ) {
    }

    public function purpose(): string
    {
        return InvitationService::LINK_PURPOSE;
    }

    public function moduleKey(): ?string
    {
        return CustomerSatisfactionModule::KEY;
    }

    public function show(PublicLink $link, Request $request): Response
    {
        $context = $this->responses->context($link);
        if ($context === null) {
            return $this->render('public/unavailable', ['reason' => 'invalid'], 404, $link);
        }
        $this->links->markOpened($link);

        return $this->render('cx/public-survey', ['survey' => $context['survey'], 'questions' => $context['questions'], 'token' => (string) $request->attribute('token'), 'errors' => [], 'old' => []], 200, $link);
    }

    public function submit(PublicLink $link, Request $request): Response
    {
        $context = $this->responses->context($link);
        if ($context === null) {
            return $this->render('public/unavailable', ['reason' => 'invalid'], 404, $link);
        }
        $result = $this->responses->submit($link, $request->all());
        if ($result['ok']) {
            return $this->render('cx/public-thanks', ['survey' => $context['survey']], 200, $link);
        }
        if (isset($result['errors']['_'])) {
            return $this->render('public/unavailable', ['reason' => 'invalid'], 404, $link);
        }

        return $this->render('cx/public-survey', [
            'survey' => $context['survey'],
            'questions' => $context['questions'],
            'token' => (string) $request->attribute('token'),
            'errors' => $result['errors'],
            'old' => $request->all(),
        ], 422, $link);
    }

    private function render(string $view, array $data, int $status, PublicLink $link): Response
    {
        $organization = (new OrganizationRepository())->findById($link->organizationId);
        $data['organizationName'] = (string) ($organization['name'] ?? '');

        return Response::html($this->templates->renderWithLayout($view, 'public_layout', $data), $status);
    }
}
