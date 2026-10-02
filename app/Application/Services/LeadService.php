<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\LeadRepository;
use App\Infrastructure\Validation\Validator;

final class LeadService
{
    public function __construct(
        private readonly LeadRepository $leads,
        private readonly ContactRepository $contacts,
        private readonly CompanyRepository $companies,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::LEADS_VIEW);

        return $this->leads->search($filters);
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::LEADS_VIEW);

        return $this->requireLead($id);
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::LEADS_CREATE);

        $validator = Validator::make($input, ['first_name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $fields = $this->fieldsFromInput($input);
        $fields['owner_id'] = (int) $actingUser['id'];

        $id = $this->leads->create($fields);
        $this->timeline->record('lead', $id, 'created', 'Lead created', (int) $actingUser['id']);

        return $this->leads->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $lead = $this->requireLead($id);
        $this->authorizeRecord($actingUser, Permission::LEADS_EDIT, $lead);

        $validator = Validator::make($input, ['first_name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $this->leads->updateLead($id, $this->fieldsFromInput($input));
        $this->timeline->record('lead', $id, 'updated', 'Lead details updated', (int) $actingUser['id']);

        return $this->leads->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $lead = $this->requireLead($id);
        $this->authorizeRecord($actingUser, Permission::LEADS_DELETE, $lead);

        $this->leads->deleteLead($id);
    }

    /**
     * Per Phase 0 §21: converts a lead into real CRM entities. Creates
     * (or reuses, if company_name matches an existing company exactly)
     * a Company when company_name is set, then always creates a
     * Contact linked to it, then marks the lead converted with both
     * ids recorded — the lead row itself is kept (not deleted) as a
     * historical record of where the contact came from.
     */
    public function convert(array $actingUser, int $id): array
    {
        $lead = $this->requireLead($id);
        $this->authorizeRecord($actingUser, Permission::LEADS_CONVERT, $lead);

        if ($lead['status'] === 'converted') {
            throw new ValidationException(['lead' => ['This lead has already been converted.']]);
        }

        $companyId = null;

        if (!empty($lead['company_name'])) {
            $existing = $this->companies->search(['search' => $lead['company_name']]);
            $exactMatch = null;
            foreach ($existing as $candidate) {
                if (strcasecmp($candidate['name'], $lead['company_name']) === 0) {
                    $exactMatch = $candidate;
                    break;
                }
            }

            $companyId = $exactMatch !== null
                ? (int) $exactMatch['id']
                : $this->companies->create([
                    'name' => $lead['company_name'],
                    'owner_id' => (int) $actingUser['id'],
                ]);
        }

        $contactId = $this->contacts->create([
            'first_name' => $lead['first_name'],
            'last_name' => $lead['last_name'],
            'email' => $lead['email'],
            'phone' => $lead['phone'],
            'company_id' => $companyId,
            'source' => $lead['source'],
            'owner_id' => (int) ($lead['owner_id'] ?? $actingUser['id']),
        ]);

        $this->leads->updateLead($id, [
            'status' => 'converted',
            'converted_at' => date('Y-m-d H:i:s'),
            'converted_contact_id' => $contactId,
            'converted_company_id' => $companyId,
        ]);

        $this->timeline->record('lead', $id, 'converted', 'Lead converted to contact', (int) $actingUser['id']);
        $this->timeline->record('contact', $contactId, 'created', 'Created from lead conversion', (int) $actingUser['id']);

        return $this->leads->findById($id);
    }

    private function fieldsFromInput(array $input): array
    {
        return array_filter([
            'first_name' => trim((string) $input['first_name']),
            'last_name' => $this->orNull($input, 'last_name'),
            'email' => $this->orNull($input, 'email'),
            'phone' => $this->orNull($input, 'phone'),
            'company_name' => $this->orNull($input, 'company_name'),
            'source' => $this->orNull($input, 'source'),
            'status' => in_array($input['status'] ?? null, ['new', 'contacted', 'qualified', 'unqualified'], true)
                ? $input['status'] : null,
        ], fn ($v) => $v !== null);
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function requireLead(int $id): array
    {
        $lead = $this->leads->findById($id);

        if ($lead === null) {
            throw new NotFoundException('Lead not found.');
        }

        return $lead;
    }
}
