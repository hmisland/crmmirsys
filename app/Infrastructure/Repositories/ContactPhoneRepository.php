<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class ContactPhoneRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contact_phones';
    }

    public function forContact(int $contactId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM contact_phones WHERE contact_id = :contact_id ORDER BY is_primary DESC, id',
            ['contact_id' => $contactId]
        );
    }

    /**
     * Replaces every phone number for a contact in one go — simpler
     * and less error-prone for a "some added, some removed, one now
     * primary" edit than diffing individual rows, same pattern as
     * Sales' TaskRepository::setAssignees().
     *
     * @param array<int, array{type: string, number: string, extension: ?string, is_primary: bool}> $phones
     */
    public function replaceForContact(int $contactId, array $phones): void
    {
        $this->transaction(function () use ($contactId, $phones) {
            $this->tenantDelete('contact_id = :contact_id', ['contact_id' => $contactId]);

            foreach ($phones as $phone) {
                $this->tenantInsertGetId([
                    'contact_id' => $contactId,
                    'type' => $phone['type'],
                    'number' => $phone['number'],
                    'extension' => $phone['extension'] ?? null,
                    'is_primary' => $phone['is_primary'] ? 1 : 0,
                ]);
            }
        });
    }
}
