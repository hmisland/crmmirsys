<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\BaseRepository;

final class TaxRuleRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'tax_rules';
    }

    /**
     * The rules that bundle into one tax_profile and are actually
     * effective on a given date — never a rule whose effective_from
     * is in the future relative to that date, and never one already
     * superseded by its own effective_to.
     */
    public function activeRulesForProfile(int $taxProfileId, string $onDate): array
    {
        return $this->findMany(
            'SELECT tr.* FROM tax_rules tr
             JOIN tax_profile_rules tpr ON tpr.tax_rule_id = tr.id
             WHERE tpr.tax_profile_id = :profile_id
               AND tr.effective_from <= :on_date
               AND (tr.effective_to IS NULL OR tr.effective_to >= :on_date2)',
            ['profile_id' => $taxProfileId, 'on_date' => $onDate, 'on_date2' => $onDate]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM tax_rules WHERE id = :id', ['id' => $id]);
    }
}
