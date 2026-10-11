<?php

declare(strict_types=1);

namespace Modules\Contracts;

use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Contracts\Services\ContractService;

/**
 * "Contracts" boxes on Core contact and company pages (step 2.6),
 * contributed through RelatedPanelRegistry — the host pages don't know
 * this module exists. RelatedPanelRegistry shows a panel only when
 * Contracts is available to the user and they hold contracts.view;
 * the list itself goes through ContractService, so the user's record
 * scope (own/team/department) applies to the rows too.
 */
final class ContractPanels
{
    public const LIMIT = 5;

    /** Core subject type => contract column that points at it. */
    private const PARTIES = ['contact' => 'contact_id', 'company' => 'company_id'];

    public function __construct(
        private readonly ContractService $contracts,
        private readonly TemplateEngine $templates,
    ) {
    }

    public function registerAll(RelatedPanelRegistry $panels): void
    {
        // Deal page (step 2.7): contracts linked to the deal. The panel is
        // keyed by the subject type string "deal"; RelatedPanelRegistry only
        // renders it on a page that exists, i.e. when Sales is installed.
        foreach ([...array_keys(self::PARTIES), 'deal'] as $subjectType) {
            $panels->register(new RelatedPanel(
                subjectType: $subjectType,
                id: 'contracts.' . $subjectType,
                module: ContractsModule::KEY,
                titleKey: 'contracts.title',
                render: fn (array $subject, array $user): string => $this->render($subjectType, $subject, $user),
                order: 50,
                permission: ContractPermission::VIEW,
                icon: 'file-text',
            ));
        }
    }

    public function render(string $subjectType, array $subject, array $user): string
    {
        $subjectId = (int) ($subject['id'] ?? 0);
        $translator = Locale::get();
        $fmt = new LocaleFormatter($translator !== null ? $translator->locale() : 'en');

        if ($subjectId <= 0) {
            return '';
        }

        if ($subjectType === 'deal') {
            return $this->templates->render('contracts/_panel', [
                'contracts' => $this->contracts->linkedTo($user, 'deal', $subjectId, self::LIMIT),
                'total' => $this->contracts->countLinkedTo($user, 'deal', $subjectId),
                // Step 2.8: prefilled from this deal and linked to it on save.
                'canCreate' => $this->contracts->userCan($user, ContractPermission::CREATE),
                'createUrl' => '/contracts/create?' . http_build_query(['from_deal' => $subjectId]),
                'listUrl' => '',
                'emptyKey' => 'contracts.panel_empty_deal',
                'fmt' => $fmt,
            ]);
        }

        $column = self::PARTIES[$subjectType] ?? null;
        if ($column === null) {
            return '';
        }

        $filters = [$column => $subjectId];

        return $this->templates->render('contracts/_panel', [
            'contracts' => $this->contracts->list($user, $filters, self::LIMIT, 0),
            'total' => $this->contracts->count($user, $filters),
            'canCreate' => $this->contracts->userCan($user, ContractPermission::CREATE),
            'createUrl' => '/contracts/create?' . http_build_query([$column => $subjectId]),
            'listUrl' => '/contracts?' . http_build_query([$subjectType => $subjectId]),
            'emptyKey' => 'contracts.panel_empty',
            'fmt' => $fmt,
        ]);
    }
}
