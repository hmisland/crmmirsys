<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use Modules\Sales\DealRepository;

/**
 * A task's one related record (T5): may this user use it, what is it
 * called, where does it link? One answer per (user, type, id):
 *
 *  - ok        the record exists and the user may see it (label + url);
 *  - forbidden it exists but the user may not see it, or its module is not
 *              available — nothing about it is shown;
 *  - missing   it no longer exists (shown as "deleted").
 *
 * Access is always the owning module's own rule, asked through Core's
 * RecordTypeRegistry — Sales never names Projects or Contracts:
 * projects use the rule Projects registered (permission scope OR
 * membership, ProjectService::canView — RecordTypeRegistry::findVisible()), contracts and deals the view
 * permission with the row's scope; contacts/companies/leads (Core types
 * without a finder) their view permission with the row's scope. A type
 * whose module is off for the user is "forbidden".
 */
final class TaskRelatedRecords
{
    /** Types a task may be related to (contact/company/lead kept from before). */
    public const TYPES = ['contact', 'company', 'lead', 'deal', 'project', 'contract'];

    /** Types offered in the task forms. */
    public const SELECTABLE = ['deal', 'project', 'contract'];

    /** Types whose module registered a finder: looked up through the registry. */
    private const REGISTRY_TYPES = ['deal', 'project', 'contract'];

    private const OPTION_LIMIT = 200;

    public function __construct(
        private readonly RecordTypeRegistry $recordTypes,
        private readonly AuthorizationEngine $authorization,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly LeadRepository $leads,
        private readonly DealRepository $deals,
    ) {
    }

    /**
     * Not cached: an access answer must never outlive a permission or
     * membership change. Callers ask once per task.
     *
     * @return array{state: string, type: string, id: int, label: ?string, url: ?string}
     */
    public function resolve(array $actingUser, string $type, int $id): array
    {
        $answer = fn (string $state, ?array $row = null) => [
            'state' => $state,
            'type' => $type,
            'id' => $id,
            'label' => $state === 'ok' ? $this->labelFor($type, $row) : null,
            'url' => $state === 'ok' ? $this->recordTypes->get($type)?->showUrl($id) : null,
        ];

        if ($id <= 0 || !in_array($type, self::TYPES, true)) {
            return $answer('missing');
        }

        if (in_array($type, self::REGISTRY_TYPES, true)) {
            $exists = $this->recordTypes->exists($type, $id, $actingUser);
            if ($exists === null) {
                return $answer('forbidden'); // module off for this user, or no view permission
            }
            if ($exists === false) {
                return $answer('missing');
            }
            $row = $this->recordTypes->findVisible($type, $id, $actingUser);

            return $row !== null ? $answer('ok', $row) : $answer('forbidden');
        }

        [$repository, $permission] = match ($type) {
            'contact' => [$this->contacts, Permission::CONTACTS_VIEW],
            'company' => [$this->companies, Permission::COMPANIES_VIEW],
            default => [$this->leads, Permission::LEADS_VIEW],
        };
        $row = $repository->findById($id);
        if ($row === null) {
            return $answer('missing');
        }

        return $this->authorization->can($actingUser, $permission, $row) ? $answer('ok', $row) : $answer('forbidden');
    }

    /**
     * The records this user may pick, per selectable type ("type:id" => label).
     *
     * @return array<string, array<string, string>>
     */
    public function options(array $actingUser): array
    {
        $options = [];

        // Deals are Sales' own: the deals.view rule with each row's scope.
        if ($this->recordTypes->canView('deal', $actingUser)) {
            foreach ($this->deals->search([]) as $deal) {
                if (count($options['deal'] ?? []) >= self::OPTION_LIMIT) {
                    break;
                }
                if ($this->authorization->can($actingUser, Permission::DEALS_VIEW, $deal)) {
                    $options['deal']['deal:' . $deal['id']] = $this->labelFor('deal', $deal);
                }
            }
        }

        // Projects / contracts: the owning module's own list for this user.
        foreach (['project', 'contract'] as $type) {
            foreach ($this->recordTypes->listFor($type, $actingUser, self::OPTION_LIMIT) as $row) {
                $options[$type][$type . ':' . $row['id']] = $this->labelFor($type, $row);
            }
        }

        return $options;
    }

    private function labelFor(string $type, array $row): string
    {
        $text = match ($type) {
            'contact' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'lead' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: (string) ($row['company_name'] ?? ''),
            'project' => trim((string) ($row['code'] ?? '') . ' ' . (string) ($row['name'] ?? '')),
            'contract' => trim((string) ($row['contract_number'] ?? '') . ' ' . (string) ($row['title'] ?? '')),
            default => (string) ($row['name'] ?? ''),
        };

        return $text !== '' ? $text : '#' . (int) ($row['id'] ?? 0);
    }
}
