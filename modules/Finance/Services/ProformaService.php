<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use Modules\Finance\FinancialProfileRepository;
use Modules\Finance\ProformaItemRepository;
use Modules\Finance\ProformaRepository;

final class ProformaService
{
    public function __construct(
        private readonly ProformaRepository $proformas,
        private readonly ProformaItemRepository $items,
        private readonly FinancialProfileRepository $financialProfiles,
        private readonly TaxCalculationService $tax,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function list(array $actingUser): array
    {
        $scope = $this->authorization->scopeFor($actingUser, Permission::QUOTES_VIEW);

        if ($scope === null) {
            throw new AuthorizationException();
        }

        $filters = $scope === Scope::OWN ? ['owner_id' => $actingUser['id']] : [];

        return $this->proformas->search($filters);
    }

    public function find(array $actingUser, int $id): array
    {
        $proforma = $this->requireProforma($id);
        $this->authorizeRecord($actingUser, Permission::QUOTES_VIEW, $proforma);

        return $proforma;
    }

    public function itemsFor(int $proformaId): array
    {
        return $this->items->forProforma($proformaId);
    }

    public function create(array $actingUser, array $input): array
    {
        if (!$this->authorization->can($actingUser, Permission::QUOTES_CREATE)) {
            throw new AuthorizationException();
        }

        $lines = $this->validatedLines($input);
        $header = $this->validatedHeader($actingUser, $input);

        $taxResult = $this->calculateTax($lines, $header);

        $header['organization_id'] = $actingUser['organization_id'];
        $header['owner_id'] = $actingUser['id'];
        $header['subtotal'] = $taxResult['subtotal'];
        $header['tax_total'] = $taxResult['tax_total'];
        $header['grand_total'] = $taxResult['grand_total'];
        $header['status'] = 'draft';

        $id = $this->proformas->create($header);
        $this->saveLines($id, $lines);

        return $this->proformas->findById($id);
    }

    public function update(array $actingUser, int $id, array $input): array
    {
        $proforma = $this->requireProforma($id);
        $this->authorizeRecord($actingUser, Permission::QUOTES_EDIT, $proforma);

        if ($proforma['converted_invoice_id'] !== null) {
            throw new ValidationException(['status' => ['This Proforma has already been converted to an Invoice and can no longer be edited.']]);
        }

        $lines = $this->validatedLines($input);
        $header = $this->validatedHeader($actingUser, $input);

        $taxResult = $this->calculateTax($lines, $header);

        $header['subtotal'] = $taxResult['subtotal'];
        $header['tax_total'] = $taxResult['tax_total'];
        $header['grand_total'] = $taxResult['grand_total'];

        $this->proformas->updateProforma($id, $header);
        $this->items->deleteAllFor($id);
        $this->saveLines($id, $lines);

        return $this->proformas->findById($id);
    }

    public function delete(array $actingUser, int $id): void
    {
        $proforma = $this->requireProforma($id);
        $this->authorizeRecord($actingUser, Permission::QUOTES_DELETE, $proforma);

        if ($proforma['converted_invoice_id'] !== null) {
            throw new ValidationException(['status' => ['This Proforma has already been converted to an Invoice and can no longer be deleted.']]);
        }

        $this->proformas->deleteProforma($id);
    }

    /** @return array<int, array{description: string, product_id: ?int, quantity: string, unit_price: string, discount_type: string, discount_value: string, line_total: string}> */
    private function validatedLines(array $input): array
    {
        $rows = (array) ($input['items'] ?? []);
        $lines = [];

        foreach ($rows as $row) {
            $description = trim((string) ($row['description'] ?? ''));
            if ($description === '') {
                continue;
            }

            $quantity = (string) ($row['quantity'] ?? '1');
            $unitPrice = (string) ($row['unit_price'] ?? '0');
            $discountType = in_array($row['discount_type'] ?? 'none', ['none', 'percentage', 'fixed'], true) ? $row['discount_type'] : 'none';
            $discountValue = (string) ($row['discount_value'] ?? '0');

            $gross = bcmul($quantity, $unitPrice, 8);
            $discountAmount = match ($discountType) {
                'percentage' => bcmul($gross, bcdiv($discountValue, '100', 10), 8),
                'fixed' => $discountValue,
                default => '0',
            };

            $lineTotal = bcsub($gross, $discountAmount, 8);
            if (bccomp($lineTotal, '0', 8) < 0) {
                $lineTotal = '0';
            }

            $lines[] = [
                'product_id' => isset($row['product_id']) && $row['product_id'] !== '' ? (int) $row['product_id'] : null,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'line_total' => $lineTotal,
            ];
        }

        if ($lines === []) {
            throw new ValidationException(['items' => ['Add at least one line item.']]);
        }

        return $lines;
    }

    private function validatedHeader(array $actingUser, array $input): array
    {
        if (empty($input['currency_id'])) {
            throw new ValidationException(['currency_id' => ['Currency is required.']]);
        }

        if (!in_array($input['pricing_mode'] ?? null, ['tax_inclusive', 'tax_exclusive'], true)) {
            throw new ValidationException(['pricing_mode' => ['Invalid pricing mode.']]);
        }

        $issueDate = (string) ($input['issue_date'] ?? '');
        if ($issueDate === '') {
            throw new ValidationException(['issue_date' => ['Issue date is required.']]);
        }

        $profile = $this->financialProfiles->find((int) $actingUser['organization_id']);

        return [
            'contact_id' => isset($input['contact_id']) && $input['contact_id'] !== '' ? (int) $input['contact_id'] : null,
            'issue_date' => $issueDate,
            'expiry_date' => isset($input['expiry_date']) && $input['expiry_date'] !== '' ? $input['expiry_date'] : null,
            'currency_id' => (int) $input['currency_id'],
            'pricing_mode' => $input['pricing_mode'],
            'tax_profile_id' => isset($input['tax_profile_id']) && $input['tax_profile_id'] !== '' ? (int) $input['tax_profile_id'] : ($profile['tax_profile_id'] ?? null),
            'invoice_discount' => (string) ($input['invoice_discount'] ?? '0'),
            'terms' => isset($input['terms']) && trim((string) $input['terms']) !== '' ? trim((string) $input['terms']) : null,
            'notes' => isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null,
        ];
    }

    private function calculateTax(array $lines, array $header): array
    {
        if ($header['tax_profile_id'] === null) {
            // No tax profile resolved (org hasn't picked one yet, and
            // none passed explicitly) — treat as untaxed rather than
            // guessing; the UI should steer the org to set one on
            // /finance/settings.
            $subtotal = '0';
            foreach ($lines as $line) {
                $subtotal = bcadd($subtotal, $line['line_total'], 8);
            }
            $taxable = bcsub($subtotal, $header['invoice_discount'], 8);

            return ['subtotal' => $subtotal, 'tax_total' => '0', 'grand_total' => $taxable];
        }

        $taxLines = array_map(
            fn (array $l) => ['amount' => $l['line_total'], 'discount' => '0'],
            $lines
        );

        return $this->tax->calculate(
            $taxLines,
            $header['tax_profile_id'],
            $header['issue_date'],
            $header['pricing_mode'],
            $header['invoice_discount']
        );
    }

    private function saveLines(int $proformaId, array $lines): void
    {
        foreach ($lines as $position => $line) {
            $line['proforma_id'] = $proformaId;
            $line['position'] = $position;
            $this->items->create($line);
        }
    }

    private function authorizeRecord(array $actingUser, string $permission, array $record): void
    {
        if (!$this->authorization->can($actingUser, $permission, $record)) {
            throw new AuthorizationException();
        }
    }

    private function requireProforma(int $id): array
    {
        $proforma = $this->proformas->findById($id);

        if ($proforma === null) {
            throw new NotFoundException('Proforma not found.');
        }

        return $proforma;
    }
}
