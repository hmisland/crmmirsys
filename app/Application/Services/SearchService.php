<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;

/**
 * Per Phase 0 §46: keyword search via SQL LIKE for the MVP — full-
 * text/semantic/vector search is explicitly future work, not required
 * now. Each entity type is only searched if the caller actually has
 * view permission for it, so results never leak what the caller
 * couldn't otherwise see.
 */
final class SearchService
{
    public function __construct(
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly LeadRepository $leads,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    /**
     * @return array{contacts: array, companies: array, leads: array}
     */
    public function search(array $actingUser, string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return ['contacts' => [], 'companies' => [], 'leads' => []];
        }

        return [
            'contacts' => $this->authorization->can($actingUser, Permission::CONTACTS_VIEW)
                ? $this->contacts->search(['search' => $term]) : [],
            'companies' => $this->authorization->can($actingUser, Permission::COMPANIES_VIEW)
                ? $this->companies->search(['search' => $term]) : [],
            'leads' => $this->authorization->can($actingUser, Permission::LEADS_VIEW)
                ? $this->leads->search(['search' => $term]) : [],
        ];
    }
}
