<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use Modules\CustomerSatisfaction\CxPermission;
use Modules\CustomerSatisfaction\CxRepository;

/**
 * Minimal survey management (deliberately not a survey builder): one
 * main score question (CSAT 1–5, NPS 0–10 or CES 1–7) plus an optional
 * free-text comment, a trigger, a send delay, link validity and a
 * preferred channel. The data model (cx_survey_questions) already
 * holds more questions for a later builder.
 */
final class SurveyService
{
    public const TRIGGERS = ['order.completed'];
    public const CHANNELS = ['sms', 'email', 'bale'];

    public function __construct(
        private readonly CxRepository $repository,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function canManage(array $user): bool
    {
        return $this->authorization->can($user, CxPermission::MANAGE);
    }

    /** Survey + its form values (main question label, comment question). */
    public function find(int $id): array
    {
        $survey = $this->repository->survey($id);
        if ($survey === null) {
            throw new NotFoundException();
        }
        $survey['question_label'] = '';
        $survey['ask_comment'] = false;
        $survey['comment_label'] = '';
        foreach ($this->repository->questions($id) as $question) {
            if ($question['kind'] === 'score') {
                $survey['question_label'] = $question['label'];
            } elseif ($question['kind'] === 'text') {
                $survey['ask_comment'] = true;
                $survey['comment_label'] = $question['label'];
            }
        }

        return $survey;
    }

    /** @return array{id: ?int, errors: array<string, string>} */
    public function save(array $user, ?int $id, array $input): array
    {
        if (!$this->canManage($user)) {
            throw new AuthorizationException();
        }
        $existing = $id !== null ? $this->find($id) : null;
        [$fields, $questions, $errors] = $this->validate($input);
        if ($existing !== null && $existing['type'] !== $fields['type'] && $this->repository->responseCount($id) > 0) {
            $errors['type'] = 'cx.error.type_locked';
        }
        if ($errors !== []) {
            return ['id' => $id, 'errors' => $errors];
        }

        $fields['updated_by_user_id'] = (int) $user['id'];
        $id = $this->repository->atomic(function () use ($id, $fields, $questions, $user) {
            if ($id === null) {
                $id = $this->repository->createSurvey($fields + ['created_by_user_id' => (int) $user['id']]);
            } else {
                $this->repository->updateSurvey($id, $fields);
            }
            $this->syncQuestions($id, $fields['type'], $questions);

            return $id;
        });

        return ['id' => $id, 'errors' => []];
    }

    /** Updates the score/comment questions in place (answers keep pointing at them). */
    private function syncQuestions(int $surveyId, string $type, array $questions): void
    {
        [$min, $max] = SurveyScale::range($type);
        $byKind = [];
        foreach ($this->repository->questions($surveyId, false) as $question) {
            $byKind[$question['kind']] ??= $question;
        }

        $score = ['position' => 1, 'label' => $questions['score'], 'scale_min' => $min, 'scale_max' => $max, 'required' => 1, 'active' => 1];
        isset($byKind['score'])
            ? $this->repository->updateQuestion((int) $byKind['score']['id'], $score)
            : $this->repository->addQuestion($surveyId, ['kind' => 'score'] + $score);

        if ($questions['comment'] !== null) {
            $text = ['position' => 2, 'label' => $questions['comment'], 'required' => 0, 'active' => 1];
            isset($byKind['text'])
                ? $this->repository->updateQuestion((int) $byKind['text']['id'], $text)
                : $this->repository->addQuestion($surveyId, ['kind' => 'text'] + $text);
        } elseif (isset($byKind['text'])) {
            $this->repository->updateQuestion((int) $byKind['text']['id'], ['active' => 0]);
        }
    }

    /** @return array{0: array, 1: array{score: string, comment: ?string}, 2: array<string, string>} */
    private function validate(array $input): array
    {
        $errors = [];
        $text = fn (string $key, int $max): ?string => ($v = trim((string) ($input[$key] ?? ''))) === '' ? null : mb_substr($v, 0, $max);

        $name = $text('name', 150);
        if ($name === null) {
            $errors['name'] = 'cx.error.name';
        }
        $type = (string) ($input['type'] ?? 'csat');
        if (!in_array($type, SurveyScale::TYPES, true)) {
            $errors['type'] = 'cx.error.type';
        }
        $trigger = (string) ($input['trigger_event'] ?? '');
        if ($trigger !== '' && !in_array($trigger, self::TRIGGERS, true)) {
            $errors['trigger_event'] = 'cx.error.trigger';
        }
        $channel = (string) ($input['channel'] ?? '');
        if ($channel !== '' && !in_array($channel, self::CHANNELS, true)) {
            $errors['channel'] = 'cx.error.channel';
        }
        $delay = (int) ($input['send_delay_minutes'] ?? 60);
        if ($delay < 0 || $delay > 43200) {
            $errors['send_delay_minutes'] = 'cx.error.delay';
        }
        $valid = (int) ($input['link_valid_days'] ?? 14);
        if ($valid < 1 || $valid > 90) {
            $errors['link_valid_days'] = 'cx.error.valid_days';
        }
        $question = $text('question_label', 500);
        if ($question === null) {
            $errors['question_label'] = 'cx.error.question';
        }
        $askComment = !empty($input['ask_comment']);

        return [
            [
                'name' => (string) $name,
                'type' => $type,
                'status' => ($input['status'] ?? '') === 'active' ? 'active' : 'inactive',
                'trigger_event' => $trigger !== '' ? $trigger : null,
                'send_delay_minutes' => $delay,
                'link_valid_days' => $valid,
                'channel' => $channel !== '' ? $channel : null,
                'intro_text' => $text('intro_text', 500),
                'message_text' => $text('message_text', 500),
                'thank_you_text' => $text('thank_you_text', 500),
            ],
            ['score' => (string) $question, 'comment' => $askComment ? ($text('comment_label', 500) ?? '') : null],
            $errors,
        ];
    }
}
