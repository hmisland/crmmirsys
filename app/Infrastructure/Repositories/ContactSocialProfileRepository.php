<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

final class ContactSocialProfileRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'contact_social_profiles';
    }

    public function forContact(int $contactId): array
    {
        return $this->tenantFindMany(
            'SELECT * FROM contact_social_profiles WHERE contact_id = :contact_id ORDER BY id',
            ['contact_id' => $contactId]
        );
    }

    /**
     * @param array<int, array{platform: string, custom_label: ?string, value: string}> $profiles
     */
    public function replaceForContact(int $contactId, array $profiles): void
    {
        $this->transaction(function () use ($contactId, $profiles) {
            $this->tenantDelete('contact_id = :contact_id', ['contact_id' => $contactId]);

            foreach ($profiles as $profile) {
                $this->tenantInsertGetId([
                    'contact_id' => $contactId,
                    'platform' => $profile['platform'],
                    'custom_label' => $profile['custom_label'],
                    'value' => $profile['value'],
                ]);
            }
        });
    }
}
