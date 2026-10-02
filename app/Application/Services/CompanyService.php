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
use App\Infrastructure\Validation\Validator;

final class CompanyService
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ContactRepository $contacts,
        private readonly TimelineService $timeline,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser, array $filters = []): array
    {
        $this->authorize($actingUser, Permission::COMPANIES_VIEW);

        return $this->companies->search($filters);
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

    private function fieldsFromInput(array $input, ?int $ownerIdIfNew): array
    {
        $fields = array_filter([
            'name' => trim((string) $input['name']),
            'website' => $this->orNull($input, 'website'),
            'email' => $this->orNull($input, 'email'),
            'phone' => $this->orNull($input, 'phone'),
            'address' => $this->orNull($input, 'address'),
            'country' => $this->orNull($input, 'country'),
            'city' => $this->orNull($input, 'city'),
            'industry' => $this->orNull($input, 'industry'),
            'employees_count' => isset($input['employees_count']) && $input['employees_count'] !== ''
                ? (int) $input['employees_count'] : null,
        ], fn ($v) => $v !== null);

        if ($ownerIdIfNew !== null) {
            $fields['owner_id'] = $ownerIdIfNew;
        }

        return $fields;
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

    private function requireCompany(int $id): array
    {
        $company = $this->companies->findById($id);

        if ($company === null) {
            throw new NotFoundException('Company not found.');
        }

        return $company;
    }
}
