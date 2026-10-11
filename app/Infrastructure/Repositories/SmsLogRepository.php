<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * sms_logs: one row per SMS attempt, from any part of the app. Written
 * by SmsService only. Not tenant-scoped through TenantScopedRepository
 * because rows may have no organization (Super Admin test sends) and the
 * only reader is the Super Admin SMS page, which shows all of them.
 */
final class SmsLogRepository extends BaseRepository
{
    public function create(array $fields): int
    {
        return $this->insertGetId('sms_logs', $fields);
    }

    /** All organizations (Super Admin) or one organization's rows. */
    public function recent(int $limit = 50, ?int $organizationId = null): array
    {
        $where = $organizationId !== null ? 'WHERE l.organization_id = :org_id' : '';
        $params = $organizationId !== null ? ['org_id' => $organizationId] : [];

        try {
            return $this->findMany(
                "SELECT l.*, (SELECT name FROM organizations WHERE organizations.id = l.organization_id) AS organization_name
                 FROM sms_logs l {$where} ORDER BY l.id DESC LIMIT " . max(1, min($limit, 500)),
                $params
            );
        } catch (\PDOException) {
            return [];
        }
    }
}
