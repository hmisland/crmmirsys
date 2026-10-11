<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Repositories\CompanyCategoryRepository;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Validation\Validator;

final class CompanyService
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
        private readonly CompanyCategoryRepository $categories = new CompanyCategoryRepository(),
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::COMPANIES_VIEW);

        return $this->companies->search($filters);
    }

    /** One page of list() — for the paginated list view. */
    public function listPage(array $actingUser, array $filters, int $limit, int $offset): array
    {
        $this->authorize($actingUser, Permission::COMPANIES_VIEW);

        return $this->companies->search($filters, $limit, $offset);
    }

    public function count(array $actingUser, array $filters = []): int
    {
        $this->authorize($actingUser, Permission::COMPANIES_VIEW);

        return $this->companies->countSearch($filters);
    }

    public function find(array $actingUser, int $id): array
    {
        $this->authorize($actingUser, Permission::COMPANIES_VIEW);

        return $this->requireCompany($id);
    }

    public function create(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::COMPANIES_CREATE);

        $validator = Validator::make($input, ['name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $id = $this->companies->create($this->fieldsFromInput($input, (int) $actingUser['id']));
        $this->timeline->record('company', $id, 'created', 'Company created', (int) $actingUser['id']);

        return $this->companies->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $company = $this->requireCompany($id);
        $this->authorizeRecord($actingUser, Permission::COMPANIES_EDIT, $company);

        $validator = Validator::make($input, ['name' => 'required|min:1']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $this->companies->updateCompany($id, $this->fieldsFromInput($input, null));
        $this->timeline->record('company', $id, 'updated', 'Company details updated', (int) $actingUser['id']);

        return $this->companies->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $company = $this->requireCompany($id);
        $this->authorizeRecord($actingUser, Permission::COMPANIES_DELETE, $company);

        if ($this->contacts->countForCompany($id) > 0) {
            throw new ValidationException(['company' => ['This company still has contacts linked to it.']]);
        }

        $this->companies->deleteCompany($id);
    }

    /**
     * On create, empty fields are simply left out. On update, only fields
     * the form actually sent are written — an empty one as NULL, so
     * clearing a field really clears it, while a partial form (e.g. the
     * custom-fields form on the company page) leaves the rest untouched.
     */
    private function fieldsFromInput(array $input, ?int $ownerIdIfNew): array
    {
        $fields = [
            'name' => trim((string) $input['name']),
            'category_id' => $this->categoryIdFromInput($input),
            'brand' => $this->orNull($input, 'brand', 150),
            'national_id' => $this->orNull($input, 'national_id', 30),
            'economic_code' => $this->orNull($input, 'economic_code', 30),
            'registration_number' => $this->orNull($input, 'registration_number', 50),
            'ceo_name' => $this->orNull($input, 'ceo_name', 150),
            'website' => $this->orNull($input, 'website'),
            'email' => $this->orNull($input, 'email'),
            'phone' => $this->orNull($input, 'phone', 50),
            'address' => $this->orNull($input, 'address'),
            'country' => $this->orNull($input, 'country', 100),
            'city' => $this->orNull($input, 'city', 100),
            'industry' => $this->orNull($input, 'industry', 100),
            'employees_count' => isset($input['employees_count']) && $input['employees_count'] !== ''
                ? max(0, (int) $input['employees_count']) : null,
            'description' => $this->orNull($input, 'description', 65000),
        ];

        if ($ownerIdIfNew !== null) {
            $fields = array_filter($fields, fn ($v) => $v !== null);
            $fields['owner_id'] = $ownerIdIfNew;

            return $fields;
        }

        return array_intersect_key($fields, $input + ['name' => true]);
    }

    /** A category of this organization (tenant-scoped lookup), or null. */
    private function categoryIdFromInput(array $input): ?int
    {
        $id = (int) ($input['category_id'] ?? 0);

        return $id > 0 && $this->categories->findById($id) !== null ? $id : null;
    }

    /**
     * Links existing contacts to the company. Each contact needs the
     * acting user's edit permission on that contact; others are skipped.
     *
     * @param array<int|string, mixed> $contactIds
     * @return array{assigned: int, skipped: int}
     */
    public function assignContacts(array $actingUser, int $companyId, array $contactIds): array
    {
        $assigned = 0;
        $skipped = 0;
        foreach (array_unique(array_filter(array_map('intval', $contactIds))) as $contactId) {
            $contact = $this->contacts->findById($contactId);
            if ($contact === null || !$this->authorization->can($actingUser, Permission::CONTACTS_EDIT, $contact)) {
                $skipped++;
                continue;
            }
            $this->contacts->updateContact($contactId, ['company_id' => $companyId]);
            $this->timeline->record('contact', $contactId, 'updated', 'Linked to a company', (int) $actingUser['id']);
            $assigned++;
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    private function orNull(array $input, string $key, int $maxLength = 255): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
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

    private function requireCompany(int $id): array
    {
        $company = $this->companies->findById($id);

        if ($company === null) {
            throw new NotFoundException('Company not found.');
        }

        return $company;
    }

    /**
     * Deletes every id the acting user may delete; the rest are skipped
     * (not theirs, already gone, or blocked by a business rule) — each
     * one goes through delete() and its record-level permission check.
     *
     * @param array<int|string, mixed> $ids
     * @return array{deleted: int, skipped: int}
     */
    public function bulkDelete(array $actingUser, array $ids): array
    {
        $deleted = 0;
        $skipped = 0;

        foreach (array_slice(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)), 0, 500) as $id) {
            try {
                $this->delete($actingUser, $id);
                $deleted++;
            } catch (AuthorizationException | NotFoundException | ValidationException) {
                $skipped++;
            }
        }

        return ['deleted' => $deleted, 'skipped' => $skipped];
    }

    /**
     * Per-row edit/delete flags for a list page — the same record-level
     * checks update()/delete() enforce (organization scope: every row;
     * own scope: only the user's own rows).
     *
     * @return array<int, array{edit: bool, delete: bool}>
     */
    public function rowPermissions(array $actingUser, array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = [
                'edit' => $this->authorization->can($actingUser, Permission::COMPANIES_EDIT, $row),
                'delete' => $this->authorization->can($actingUser, Permission::COMPANIES_DELETE, $row),
            ];
        }

        return $result;
    }
}
