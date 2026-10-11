<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;

use function App\Infrastructure\Theme\t;

/**
 * Server-side checks on a proforma / invoice header that the form can't be
 * trusted with (docs/contract-finance-design.md, F1 / D4):
 * - contact_id must be a contact of the caller's organization
 *   (ContactRepository is tenant-scoped, so another organization's id is
 *   simply "not found"); the document lists show the contact's name, so an
 *   unchecked id would surface another organization's data;
 * - currency_id must exist and be active.
 * Nothing here touches amounts, tax, numbering or status.
 */
final class DocumentHeaderGuard
{
    public function __construct(
        private readonly ContactRepository $contacts = new ContactRepository(),
        private readonly CurrencyRepository $currencies = new CurrencyRepository(),
    ) {
    }

    /** @throws ValidationException with every failing field */
    public function check(mixed $contactId, mixed $currencyId): void
    {
        $errors = [];

        if ($contactId !== null && $contactId !== '' && ((int) $contactId <= 0 || $this->contacts->findById((int) $contactId) === null)) {
            $errors['contact_id'][] = t('finance.error.invalid_contact');
        }

        if ($currencyId !== null && $currencyId !== '') {
            $currency = (int) $currencyId > 0 ? $this->currencies->findById((int) $currencyId) : null;
            if ($currency === null || empty($currency['is_active'])) {
                $errors['currency_id'][] = t('finance.error.invalid_currency');
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }
}
