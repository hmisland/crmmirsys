<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use Modules\Finance\FinancialProfileRepository;

final class FinancialProfileService
{
    public function __construct(
        private readonly FinancialProfileRepository $profiles,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function get(array $actingUser): array
    {
        $this->authorize($actingUser, Permission::FINANCE_SETTINGS_VIEW);

        return $this->profiles->find((int) $actingUser['organization_id']) ?? [
            'organization_id' => $actingUser['organization_id'],
            'entity_type' => 'legal',
            'legal_name' => null,
            'first_name' => null,
            'last_name' => null,
            'tax_identification_number' => null,
            'country_id' => null,
            'tax_jurisdiction_id' => null,
            'tax_profile_id' => null,
            'base_currency_id' => null,
            'invoice_prefix' => 'INV-',
            'pricing_mode' => 'tax_exclusive',
            'invoice_final_rounding' => 0,
        ];
    }

    public function update(array $actingUser, array $input): array
    {
        $this->authorize($actingUser, Permission::FINANCE_SETTINGS_EDIT);

        if (!in_array($input['entity_type'] ?? null, ['legal', 'natural'], true)) {
            throw new ValidationException(['entity_type' => ['Choose whether this is a legal entity or a natural person.']]);
        }

        if ($input['entity_type'] === 'legal' && trim((string) ($input['legal_name'] ?? '')) === '') {
            throw new ValidationException(['legal_name' => ['Legal name is required for a legal entity.']]);
        }

        if ($input['entity_type'] === 'natural' && trim((string) ($input['first_name'] ?? '')) === '') {
            throw new ValidationException(['first_name' => ['First name is required for a natural person.']]);
        }

        if (trim((string) ($input['invoice_prefix'] ?? '')) === '') {
            throw new ValidationException(['invoice_prefix' => ['Invoice prefix is required.']]);
        }

        if (!in_array($input['pricing_mode'] ?? null, ['tax_inclusive', 'tax_exclusive'], true)) {
            throw new ValidationException(['pricing_mode' => ['Invalid pricing mode.']]);
        }

        $organizationId = (int) $actingUser['organization_id'];

        $this->profiles->upsert($organizationId, [
            'entity_type' => $input['entity_type'],
            'legal_name' => $input['entity_type'] === 'legal' ? $this->orNull($input, 'legal_name') : null,
            'first_name' => $input['entity_type'] === 'natural' ? $this->orNull($input, 'first_name') : null,
            'last_name' => $input['entity_type'] === 'natural' ? $this->orNull($input, 'last_name') : null,
            'tax_identification_number' => $this->orNull($input, 'tax_identification_number'),
            'country_id' => $this->orNullInt($input, 'country_id'),
            'tax_jurisdiction_id' => $this->orNullInt($input, 'tax_jurisdiction_id'),
            'tax_profile_id' => $this->orNullInt($input, 'tax_profile_id'),
            'base_currency_id' => $this->orNullInt($input, 'base_currency_id'),
            'invoice_prefix' => trim((string) $input['invoice_prefix']),
            'pricing_mode' => $input['pricing_mode'],
            // Optional final invoice rounding (migration 0024): the organization default, OFF unless ticked.
            'invoice_final_rounding' => !empty($input['invoice_final_rounding']) ? 1 : 0,
        ]);

        return $this->profiles->find($organizationId);
    }

    private function orNull(array $input, string $key): ?string
    {
        $value = isset($input[$key]) ? trim((string) $input[$key]) : '';

        return $value === '' ? null : $value;
    }

    private function orNullInt(array $input, string $key): ?int
    {
        return isset($input[$key]) && $input[$key] !== '' ? (int) $input[$key] : null;
    }

    private function authorize(array $actingUser, string $permission): void
    {
        if (!$this->authorization->can($actingUser, $permission)) {
            throw new AuthorizationException();
        }
    }
}
