<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Infrastructure\PublicLinks\PublicLink;
use App\Infrastructure\PublicLinks\PublicLinkService;
use Modules\CustomerSatisfaction\CxRepository;

/**
 * The customer's answer, from the public survey page. Runs inside the
 * link's organization (PublicLinkController). One response per
 * invitation: the single-use link is consumed atomically first, so a
 * double submit or a replay cannot create a second response.
 * A dissatisfied/detractor answer opens a follow-up for the team.
 */
final class ResponseService
{
    public const COMMENT_MAX = 2000;

    public function __construct(
        private readonly CxRepository $repository,
        private readonly PublicLinkService $links,
    ) {
    }

    /**
     * What the public page needs — and nothing more (no ids, no order
     * data, no other customer's information).
     *
     * @return array{survey: array, questions: array[], invitation: array}|null
     */
    public function context(PublicLink $link): ?array
    {
        if ($link->subjectType !== InvitationService::LINK_SUBJECT) {
            return null;
        }
        $invitation = $this->repository->invitation($link->subjectId);
        if ($invitation === null || !in_array($invitation['status'], ['sent'], true)) {
            return null;
        }
        $survey = $this->repository->survey((int) $invitation['survey_id']);
        if ($survey === null) {
            return null;
        }

        return ['survey' => $survey, 'questions' => $this->repository->questions((int) $survey['id']), 'invitation' => $invitation];
    }

    /**
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function submit(PublicLink $link, array $input): array
    {
        $context = $this->context($link);
        if ($context === null) {
            return ['ok' => false, 'errors' => ['_' => 'cx.public.unavailable']];
        }
        ['survey' => $survey, 'questions' => $questions, 'invitation' => $invitation] = $context;

        $answers = [];
        $score = null;
        $comment = null;
        $errors = [];
        foreach ($questions as $question) {
            $key = 'q' . $question['id'];
            $raw = trim((string) ($input[$key] ?? ''));
            if ($question['kind'] === 'text') {
                if ($raw !== '') {
                    $text = mb_substr($raw, 0, self::COMMENT_MAX);
                    $answers[] = [(int) $question['id'], null, $text];
                    $comment ??= $text;
                }
                continue;
            }
            if ($raw === '' || preg_match('/^\d{1,2}$/', $raw) !== 1
                || (int) $raw < (int) $question['scale_min'] || (int) $raw > (int) $question['scale_max']) {
                if ((int) $question['required'] === 1 || $raw !== '') {
                    $errors[$key] = 'cx.public.error.choose_score';
                }
                continue;
            }
            $answers[] = [(int) $question['id'], (int) $raw, null];
            if ($question['kind'] === 'score') {
                $score ??= (int) $raw;
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $sentiment = $score !== null ? SurveyScale::sentiment((string) $survey['type'], $score) : null;

        $saved = $this->repository->atomic(function () use ($link, $survey, $invitation, $score, $sentiment, $comment, $answers): bool {
            if (!$this->links->consume($link)) {
                return false;
            }
            if (!empty($invitation['is_test'])) {
                // Phase E: a test answer shows the real thank-you page but is not stored as a response.
                $this->repository->updateInvitation((int) $invitation['id'], (int) $invitation['organization_id'], ['status' => 'responded']);

                return true;
            }
            $responseId = $this->repository->createResponse([
                'survey_id' => (int) $survey['id'],
                'invitation_id' => (int) $invitation['id'],
                'score' => $score,
                'sentiment' => $sentiment,
                'comment' => $comment,
                'follow_up_status' => $sentiment !== null && SurveyScale::needsFollowUp($sentiment) ? 'open' : 'none',
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($answers as [$questionId, $numeric, $text]) {
                $this->repository->addAnswer($responseId, $questionId, $numeric, $text);
            }
            $this->repository->updateInvitation((int) $invitation['id'], (int) $invitation['organization_id'], ['status' => 'responded']);

            return true;
        });

        return $saved ? ['ok' => true, 'errors' => []] : ['ok' => false, 'errors' => ['_' => 'cx.public.unavailable']];
    }
}
