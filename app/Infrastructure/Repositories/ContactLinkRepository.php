<?php

declare(strict_types=1);

namespace App\Infrastructure\Repositories;

/**
 * Read-only lookups of everything other modules attach to a contact
 * (invoices, proformas, deals, tasks, follow-ups, appointments,
 * tickets, client records, notes) — used by the contact page's
 * "related" summary and by the CSV-import rollback to tell whether an
 * imported contact has been used since.
 *
 * Every query is explicitly scoped to the current organization. Module
 * tables may not exist on an install where that module was never
 * migrated, so a missing table simply counts as "nothing linked".
 */
final class ContactLinkRepository extends TenantScopedRepository
{
    /**
     * type => [table, WHERE condition on the contact]. The condition
     * always binds :contact_id.
     */
    private const SOURCES = [
        'invoices' => ['invoices', 'contact_id = :contact_id'],
        'proformas' => ['proformas', 'contact_id = :contact_id'],
        'deals' => ['deals', 'contact_id = :contact_id'],
        'tasks' => ['tasks', "related_type = 'contact' AND related_id = :contact_id AND archived_at IS NULL AND deleted_at IS NULL"],
        'followups' => ['follow_ups', "related_type = 'contact' AND related_id = :contact_id"],
        'appointments' => ['appointments', 'contact_id = :contact_id'],
        'tickets' => ['tickets', 'contact_id = :contact_id'],
        'client_records' => ['client_records', 'contact_id = :contact_id'],
        'notes' => ['notes', "notable_type = 'contact' AND notable_id = :contact_id"],
    ];

    protected function table(): string
    {
        return 'contacts';
    }

    /**
     * @param string[] $types subset of the SOURCES keys
     * @param array<string, int|null> $ownerFilter type => owner user id (OWN-scope callers), or null
     * @return array<string, int> type => count
     */
    public function counts(int $contactId, array $types, array $ownerFilter = []): array
    {
        $counts = [];

        foreach ($types as $type) {
            if (!isset(self::SOURCES[$type])) {
                continue;
            }

            [$table, $condition] = self::SOURCES[$type];
            [$ownerSql, $ownerParams] = $this->ownerCondition($type, $ownerFilter[$type] ?? null);

            $row = $this->safeFindOne(
                "SELECT COUNT(*) AS c FROM {$table} WHERE organization_id = :org_id AND {$condition}{$ownerSql}",
                array_merge(['org_id' => OrganizationContext::id(), 'contact_id' => $contactId], $ownerParams)
            );

            $counts[$type] = (int) ($row['c'] ?? 0);
        }

        return $counts;
    }

    /** Rows for one related list, newest first, at most $limit. */
    public function rows(string $type, int $contactId, ?int $ownerId = null, int $limit = 100): array
    {
        $params = ['org_id' => OrganizationContext::id(), 'contact_id' => $contactId];
        [$ownerSql, $ownerParams] = $this->ownerCondition($type, $ownerId, 'x.');
        $params = array_merge($params, $ownerParams);
        $limit = max(1, min($limit, 500));

        $sql = match ($type) {
            'invoices' => "SELECT x.id, x.invoice_number, x.issue_date, x.due_date, x.grand_total, x.paid_total, x.status,
                    (SELECT code FROM currencies WHERE currencies.id = x.currency_id) AS currency_code,
                    (SELECT COALESCE(SUM(c.amount), 0) FROM credit_notes c
                      WHERE c.invoice_id = x.id AND c.organization_id = x.organization_id) AS credited_total
                FROM invoices x WHERE x.organization_id = :org_id AND x.contact_id = :contact_id{$ownerSql}
                ORDER BY x.issue_date DESC, x.id DESC",
            'proformas' => "SELECT x.id, x.issue_date, x.expiry_date, x.grand_total, x.status,
                    (SELECT code FROM currencies WHERE currencies.id = x.currency_id) AS currency_code
                FROM proformas x WHERE x.organization_id = :org_id AND x.contact_id = :contact_id{$ownerSql}
                ORDER BY x.issue_date DESC, x.id DESC",
            'deals' => "SELECT x.id, x.name, x.value, x.currency_code, x.status, x.expected_close_date,
                    (SELECT name FROM pipeline_stages WHERE pipeline_stages.id = x.stage_id) AS stage_name
                FROM deals x WHERE x.organization_id = :org_id AND x.contact_id = :contact_id{$ownerSql}
                ORDER BY x.created_at DESC",
            'tasks' => "SELECT x.id, x.title, x.due_at, x.priority, x.status
                FROM tasks x WHERE x.organization_id = :org_id AND x.related_type = 'contact' AND x.related_id = :contact_id
                    AND x.archived_at IS NULL AND x.deleted_at IS NULL{$ownerSql}
                ORDER BY x.due_at IS NULL, x.due_at DESC",
            'followups' => "SELECT x.id, x.title, x.type, x.due_at, x.priority, x.status
                FROM follow_ups x WHERE x.organization_id = :org_id AND x.related_type = 'contact' AND x.related_id = :contact_id{$ownerSql}
                ORDER BY x.due_at IS NULL, x.due_at DESC",
            'appointments' => "SELECT x.id, x.title, x.starts_at, x.ends_at, x.status
                FROM appointments x WHERE x.organization_id = :org_id AND x.contact_id = :contact_id{$ownerSql}
                ORDER BY x.starts_at DESC",
            'tickets' => "SELECT x.id, x.subject, x.status, x.priority, x.created_at
                FROM tickets x WHERE x.organization_id = :org_id AND x.contact_id = :contact_id{$ownerSql}
                ORDER BY x.created_at DESC",
            default => null,
        };

        if ($sql === null) {
            return [];
        }

        return $this->safeFindMany($sql . ' LIMIT ' . $limit, $params);
    }

    /** Whether anything at all (other than the import itself) now points at this contact. */
    public function contactInUse(int $contactId): bool
    {
        return array_sum($this->counts($contactId, array_keys(self::SOURCES))) > 0;
    }

    /** Whether any contact, lead or deal still points at this company. */
    public function companyInUse(int $companyId): bool
    {
        foreach (['contacts', 'leads', 'deals'] as $table) {
            $row = $this->safeFindOne(
                "SELECT COUNT(*) AS c FROM {$table} WHERE organization_id = :org_id AND company_id = :company_id",
                ['org_id' => OrganizationContext::id(), 'company_id' => $companyId]
            );

            if ((int) ($row['c'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    public function categoryInUse(int $categoryId): bool
    {
        $row = $this->safeFindOne(
            'SELECT COUNT(*) AS c FROM contacts WHERE organization_id = :org_id AND category_id = :category_id',
            ['org_id' => OrganizationContext::id(), 'category_id' => $categoryId]
        );

        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * Tables whose rows carry an owner — the column OWN-scoped viewers
     * are filtered on. Tickets have no owner column; for those an OWN
     * scope falls back to "created by". Tasks: created by or assigned to.
     *
     * @return array{0: string, 1: array}
     */
    private function ownerCondition(string $type, ?int $ownerId, string $alias = ''): array
    {
        if ($ownerId === null) {
            return ['', []];
        }

        $column = match ($type) {
            'tickets' => 'created_by_user_id',
            'notes' => 'user_id',
            default => 'owner_id',
        };

        // A task is "own" when the user created it or is one of its assignees
        // (the Sales TaskService rule), not only when they created it.
        if ($type === 'tasks') {
            return [" AND ({$alias}created_by_user_id = :owner_id OR {$alias}assigned_to_user_id = :owner_id2"
                . " OR {$alias}id IN (SELECT task_id FROM task_assignees WHERE user_id = :owner_id3))",
                ['owner_id' => $ownerId, 'owner_id2' => $ownerId, 'owner_id3' => $ownerId]];
        }

        return [" AND {$alias}{$column} = :owner_id", ['owner_id' => $ownerId]];
    }

    private function safeFindOne(string $sql, array $params): ?array
    {
        try {
            return $this->findOne($sql, $params);
        } catch (\PDOException) {
            return null;
        }
    }

    private function safeFindMany(string $sql, array $params): array
    {
        try {
            return $this->findMany($sql, $params);
        } catch (\PDOException) {
            return [];
        }
    }
}
