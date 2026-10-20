<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class ContactEmailRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contact_emails';
    }

    public function forContact(int $contactId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM contact_emails WHERE contact_id = :contact_id ORDER BY is_primary DESC, id',
            ['contact_id' => $contactId]
        );
    }

    /**
     * @param array<int, array{email: string, is_primary: bool}> $emails
     */
    public function replaceForContact(int $contactId, array $emails): void
    {
        $this->transaction(function () use ($contactId, $emails) {
            $this->tenantDelete('contact_id = :contact_id', ['contact_id' => $contactId]);

            foreach ($emails as $email) {
                $this->tenantInsertGetId([
                    'contact_id' => $contactId,
                    'email' => $email['email'],
                    'is_primary' => $email['is_primary'] ? 1 : 0,
                ]);
            }
        });
    }

    /** Adds one email without touching the contact's other emails (e.g. a sync filling a gap). */
    public function add(int $contactId, string $email, bool $isPrimary): void
    {
        $this->tenantInsertGetId(['contact_id' => $contactId, 'email' => $email, 'is_primary' => $isPrimary ? 1 : 0]);
    }
}
