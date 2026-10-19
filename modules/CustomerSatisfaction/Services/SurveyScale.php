<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

/**
 * The scale and the sentiment rule of each survey type:
 *  - csat: 1–5 ("how satisfied"), ≤2 dissatisfied, 3 neutral, ≥4 satisfied;
 *  - nps:  0–10 ("how likely to recommend"), ≤6 detractor, 7–8 passive, ≥9 promoter;
 *  - ces:  1–7 ("how easy"), ≤3 dissatisfied (high effort), 4 neutral, ≥5 satisfied.
 * A "dissatisfied"/"detractor" answer opens a follow-up.
 */
final class SurveyScale
{
    public const TYPES = ['csat', 'nps', 'ces'];

    /** @return array{0: int, 1: int} */
    public static function range(string $type): array
    {
        return match ($type) {
            'nps' => [0, 10],
            'ces' => [1, 7],
            default => [1, 5],
        };
    }

    public static function sentiment(string $type, int $score): string
    {
        return match ($type) {
            'nps' => $score <= 6 ? 'detractor' : ($score <= 8 ? 'passive' : 'promoter'),
            'ces' => $score <= 3 ? 'dissatisfied' : ($score === 4 ? 'neutral' : 'satisfied'),
            default => $score <= 2 ? 'dissatisfied' : ($score === 3 ? 'neutral' : 'satisfied'),
        };
    }

    public static function needsFollowUp(string $sentiment): bool
    {
        return in_array($sentiment, ['dissatisfied', 'detractor'], true);
    }
}
