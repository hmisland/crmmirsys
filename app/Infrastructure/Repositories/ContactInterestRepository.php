<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class ContactInterestRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contact_interests';
    }

    public function forContact(int $contactId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM contact_interests WHERE contact_id = :contact_id ORDER BY id',
            ['contact_id' => $contactId]
        );
    }

    /** @param string[] $interests */
    public function replaceForContact(int $contactId, array $interests): void
    {
        $this->transaction(function () use ($contactId, $interests) {
            $this->tenantDelete('contact_id = :contact_id', ['contact_id' => $contactId]);

            foreach ($interests as $interest) {
                $this->tenantInsertGetId(['contact_id' => $contactId, 'interest' => $interest]);
            }
        });
    }
}
