<?php

declare(strict_types=1);

use App\Infrastructure\Database\MigrationInterface;

/**
 * UTC storage (docs/organization-timezone-design.md).
 *
 * Until now MySQL stamped CURRENT_TIMESTAMP / ON UPDATE columns ("C1") in
 * the server's session zone (production: Asia/Tehran, +03:30) while PHP
 * wrote UTC ("C2"). This converts exactly the verified C1 columns below to
 * UTC, once, and records it in storage_timezone_state; from then on
 * Connection::applyStorageTimezone() runs every connection with session
 * time_zone '+00:00'.
 *
 *  - The legacy zone is the server's (@@global.time_zone, possibly SYSTEM),
 *    which is what every connection used so far. Each value is converted
 *    by its own instant (UNIX_TIMESTAMP under the legacy zone), so a zone
 *    with DST is handled per row; no MySQL time-zone tables are needed.
 *    Policy for a zone with DST: a wall-clock time inside the repeated
 *    autumn hour is ambiguous; MySQL's UNIX_TIMESTAMP choice is used.
 *    Values outside 1970-01-02 … 2038-01-18 are left unchanged.
 *  - Every C1 column of a table is assigned in the SAME UPDATE, so ON
 *    UPDATE CURRENT_TIMESTAMP never fires during the conversion.
 *  - C2 (PHP-written instants) and C3 (user-entered local times, DATE and
 *    TIME columns) are not touched.
 *  - Derived C1 copies converted too: contact_import_batch_items.stamp
 *    (a copy of contacts.updated_at, compared for equality on import
 *    rollback) and the DB-clock values inside marketing_campaigns.
 *    audience_snapshot ("cutoff", "as_of").
 *  - Tables or columns missing on a server are skipped; DATETIME columns
 *    with DEFAULT CURRENT_TIMESTAMP that are NOT in the verified list are
 *    left unchanged and listed in the state row ("unverified").
 *  - One transaction (all tables are InnoDB): all or nothing. Idempotent:
 *    does nothing when the state row exists. down() converts back with
 *    the recorded legacy zone and removes the state (session returns to
 *    the server zone).
 */
return new class implements MigrationInterface {
    /** Verified C1 columns (inventory of 2026-10-15): table => columns. */
    private const C1 = [
        'admin_announcement_recipients' => ['created_at'],
        'admin_announcements' => ['created_at'],
        'appointment_resources' => ['created_at', 'updated_at'],
        'appointment_types' => ['created_at', 'updated_at'],
        'appointments' => ['created_at', 'updated_at'],
        'attachments' => ['created_at'],
        'auth_tokens' => ['created_at'],
        'banks' => ['created_at'],
        'client_record_files' => ['created_at'],
        'client_record_services' => ['created_at', 'updated_at'],
        'client_record_sessions' => ['created_at', 'updated_at'],
        'client_records' => ['created_at', 'updated_at'],
        'companies' => ['created_at', 'updated_at'],
        'company_categories' => ['created_at'],
        'contact_broadcasts' => ['created_at'],
        'contact_categories' => ['created_at'],
        'contact_emails' => ['created_at'],
        'contact_import_batches' => ['created_at'],
        'contact_interests' => ['created_at'],
        'contact_phones' => ['created_at'],
        'contact_social_profiles' => ['created_at'],
        'contacts' => ['created_at', 'updated_at'],
        'contract_categories' => ['created_at', 'updated_at'],
        'contract_links' => ['created_at'],
        'contract_settings' => ['updated_at'],
        'contracts' => ['created_at', 'updated_at'],
        'credit_notes' => ['created_at'],
        'currencies' => ['created_at'],
        'custom_field_definitions' => ['created_at'],
        'custom_field_values' => ['updated_at'],
        'deals' => ['created_at', 'updated_at'],
        'demo_notes' => ['created_at'],
        'departments' => ['created_at', 'updated_at'],
        'exchange_rates' => ['effective_at'],
        'file_library_documents' => ['created_at'],
        'file_library_folders' => ['created_at'],
        'file_library_uploaders' => ['created_at'],
        'finance_budget_lines' => ['created_at', 'updated_at'],
        'finance_document_links' => ['created_at'],
        'finance_expenses' => ['created_at', 'updated_at'],
        'finance_installments' => ['created_at', 'updated_at'],
        'finance_submission_keys' => ['created_at'],
        'financial_periods' => ['created_at'],
        'financial_profiles' => ['updated_at'],
        'follow_up_recurrences' => ['created_at'],
        'follow_up_reminders' => ['created_at'],
        'follow_up_rule_executions' => ['executed_at'],
        'follow_up_rules' => ['created_at', 'updated_at'],
        'follow_ups' => ['created_at', 'updated_at'],
        'help_articles' => ['created_at', 'updated_at'],
        'invoices' => ['created_at', 'updated_at'],
        'kb_articles' => ['created_at', 'updated_at'],
        'kb_categories' => ['created_at'],
        'leads' => ['created_at', 'updated_at'],
        'login_attempts' => ['created_at'],
        'marketing_campaigns' => ['created_at', 'updated_at'],
        'marketing_messages' => ['created_at', 'updated_at'],
        'marketing_segment_contacts' => ['created_at'],
        'marketing_segments' => ['created_at', 'updated_at'],
        'marketing_suppressions' => ['created_at'],
        'marketing_templates' => ['created_at', 'updated_at'],
        'marketing_webhook_events' => ['received_at'],
        'message_attachments' => ['created_at'],
        'message_recipients' => ['created_at'],
        'messages' => ['created_at'],
        'migrations' => ['applied_at'],
        'module_migrations' => ['applied_at'],
        'modules' => ['installed_at', 'updated_at'],
        'notes' => ['created_at', 'updated_at'],
        'notification_credit_transactions' => ['created_at'],
        'notification_credits' => ['updated_at'],
        'notification_logs' => ['created_at'],
        'notification_platform_settings' => ['updated_at'],
        'notification_recipients' => ['updated_at'],
        'number_series' => ['created_at', 'updated_at'],
        'organization_email_providers' => ['created_at', 'updated_at'],
        'organization_modules' => ['created_at', 'updated_at'],
        'organization_sms_settings' => ['updated_at'],
        'organizations' => ['created_at', 'updated_at'],
        'payments' => ['created_at'],
        'pipeline_stages' => ['created_at'],
        'pipelines' => ['created_at'],
        'products' => ['created_at'],
        'proformas' => ['created_at', 'updated_at'],
        'project_links' => ['created_at'],
        'project_members' => ['created_at'],
        'project_settings' => ['updated_at'],
        'projects' => ['created_at', 'updated_at'],
        'role_permissions' => ['created_at'],
        'roles' => ['created_at', 'updated_at'],
        'sms_logs' => ['created_at'],
        'taggables' => ['created_at'],
        'tags' => ['created_at'],
        'task_assignees' => ['created_at'],
        'tasks' => ['created_at', 'updated_at'],
        'teams' => ['created_at', 'updated_at'],
        'ticket_categories' => ['created_at'],
        'ticket_replies' => ['created_at'],
        'tickets' => ['created_at', 'updated_at'],
        'timeline_events' => ['created_at'],
        'user_bank_accounts' => ['created_at'],
        'user_module_overrides' => ['created_at', 'updated_at'],
        'user_permission_grants' => ['created_at'],
        'users' => ['created_at', 'updated_at'],
        'wallet_gift_codes' => ['created_at'],
        'wallet_ledger' => ['created_at'],
        'wallet_org_settings' => ['updated_at'],
        'wallet_platform_settings' => ['updated_at'],
        'wallet_transactions' => ['created_at'],
        'wallet_withdrawal_requests' => ['requested_at'],
        'wallets' => ['created_at', 'updated_at'],
    ];

    private const LOW = '1970-01-02 00:00:00';
    private const HIGH = '2038-01-18 00:00:00';

    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS storage_timezone_state (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                legacy_zone VARCHAR(64) NOT NULL,
                legacy_system_zone VARCHAR(64) NULL,
                legacy_offset VARCHAR(10) NOT NULL,
                converted_columns TEXT NOT NULL,
                unverified_columns TEXT NOT NULL,
                converted_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
        if ($pdo->query('SELECT 1 FROM storage_timezone_state WHERE id = 1')->fetchColumn() !== false) {
            $pdo->exec("SET time_zone = '+00:00'");

            return; // already converted
        }

        $legacy = (string) $pdo->query('SELECT @@global.time_zone')->fetchColumn();
        $pdo->exec('SET time_zone = ' . $pdo->quote($legacy));
        $offset = (string) $pdo->query('SELECT TIME_FORMAT(TIMEDIFF(NOW(), UTC_TIMESTAMP()), \'%H:%i\')')->fetchColumn();
        $system = (string) $pdo->query('SELECT @@system_time_zone')->fetchColumn();
        [$columns, $unverified] = $this->plan($pdo);

        $pdo->beginTransaction();
        try {
            foreach ($columns as $table => $cols) {
                $set = implode(', ', array_map(fn (string $c) => "`{$c}` = " . $this->toUtc("`{$c}`"), $cols));
                $pdo->exec("UPDATE `{$table}` SET {$set}");
            }
            if ($this->hasColumn($pdo, 'contact_import_batch_items', 'stamp')) {
                $pdo->exec('UPDATE contact_import_batch_items SET stamp = ' . $this->toUtc('stamp') . ' WHERE stamp IS NOT NULL');
                $columns['contact_import_batch_items (derived)'] = ['stamp'];
            }
            if ($this->convertSnapshots($pdo, fn (string $v) => $this->toUtc($pdo->quote($v)))) {
                $columns['marketing_campaigns (derived)'] = ['audience_snapshot.cutoff', 'audience_snapshot.as_of'];
            }
            $pdo->prepare(
                'INSERT INTO storage_timezone_state (id, legacy_zone, legacy_system_zone, legacy_offset, converted_columns, unverified_columns, converted_at)
                 VALUES (1, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            )->execute([$legacy, $system, $offset, json_encode($columns), json_encode($unverified)]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // The rest of this request (the migrator's own bookkeeping, later migrations) writes UTC.
        $pdo->exec("SET time_zone = '+00:00'");
    }

    public function down(\PDO $pdo): void
    {
        $state = $this->tableExists($pdo, 'storage_timezone_state')
            ? $pdo->query('SELECT * FROM storage_timezone_state WHERE id = 1')->fetch(\PDO::FETCH_ASSOC)
            : false;
        if ($state === false) {
            $pdo->exec('DROP TABLE IF EXISTS storage_timezone_state');

            return;
        }

        $pdo->exec('SET time_zone = ' . $pdo->quote((string) $state['legacy_zone']));
        [$columns] = $this->plan($pdo);
        $pdo->beginTransaction();
        try {
            foreach ($columns as $table => $cols) {
                $set = implode(', ', array_map(fn (string $c) => "`{$c}` = " . $this->fromUtc("`{$c}`"), $cols));
                $pdo->exec("UPDATE `{$table}` SET {$set}");
            }
            if ($this->hasColumn($pdo, 'contact_import_batch_items', 'stamp')) {
                $pdo->exec('UPDATE contact_import_batch_items SET stamp = ' . $this->fromUtc('stamp') . ' WHERE stamp IS NOT NULL');
            }
            $this->convertSnapshots($pdo, fn (string $v) => $this->fromUtc($pdo->quote($v)));
            $pdo->exec('DELETE FROM storage_timezone_state WHERE id = 1');
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $pdo->exec('DROP TABLE IF EXISTS storage_timezone_state');
    }

    /** Legacy wall clock (session = legacy zone) → UTC wall clock, by the value's own instant. */
    private function toUtc(string $expr): string
    {
        return "IF({$expr} >= '" . self::LOW . "' AND {$expr} < '" . self::HIGH . "', '1970-01-01 00:00:00' + INTERVAL UNIX_TIMESTAMP({$expr}) SECOND, {$expr})";
    }

    /** UTC wall clock → legacy wall clock (session = legacy zone). */
    private function fromUtc(string $expr): string
    {
        return "IF({$expr} >= '" . self::LOW . "' AND {$expr} < '" . self::HIGH . "', FROM_UNIXTIME(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', {$expr})), {$expr})";
    }

    /** @return array{0: array<string, string[]>, 1: string[]} verified columns present here; unverified C1-like columns */
    private function plan(\PDO $pdo): array
    {
        $present = [];
        foreach ($pdo->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_DEFAULT AS d, DATA_TYPE AS y FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE = 'datetime'"
        )->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $present[$r['t']][$r['c']] = stripos((string) $r['d'], 'current_timestamp') !== false;
        }

        $columns = [];
        foreach (self::C1 as $table => $cols) {
            $cols = array_values(array_filter($cols, fn (string $c) => isset($present[$table][$c])));
            if ($cols !== []) {
                $columns[$table] = $cols;
            }
        }
        $unverified = [];
        foreach ($present as $table => $cols) {
            foreach ($cols as $col => $defaulted) {
                if ($defaulted && !in_array($col, self::C1[$table] ?? [], true) && $table !== 'storage_timezone_state') {
                    $unverified[] = "{$table}.{$col}";
                }
            }
        }

        return [$columns, $unverified];
    }

    /** Converts "cutoff" / "as_of" inside marketing_campaigns.audience_snapshot; true when the column exists. */
    private function convertSnapshots(\PDO $pdo, \Closure $sql): bool
    {
        if (!$this->hasColumn($pdo, 'marketing_campaigns', 'audience_snapshot')) {
            return false;
        }
        // updated_at assigned to itself: otherwise ON UPDATE CURRENT_TIMESTAMP would overwrite the converted value.
        $keep = $this->hasColumn($pdo, 'marketing_campaigns', 'updated_at') ? ', updated_at = updated_at' : '';
        $update = $pdo->prepare("UPDATE marketing_campaigns SET audience_snapshot = ?{$keep} WHERE id = ?");
        foreach ($pdo->query('SELECT id, audience_snapshot FROM marketing_campaigns WHERE audience_snapshot IS NOT NULL')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $snapshot = json_decode((string) $row['audience_snapshot'], true);
            if (!is_array($snapshot)) {
                continue;
            }
            $changed = false;
            foreach (['cutoff', 'as_of'] as $key) {
                if (is_string($snapshot[$key] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $snapshot[$key]) === 1) {
                    $snapshot[$key] = (string) $pdo->query('SELECT ' . $sql($snapshot[$key]))->fetchColumn();
                    $changed = true;
                }
            }
            if ($changed) {
                $update->execute([json_encode($snapshot, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
            }
        }

        return true;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    private function hasColumn(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);

        return $stmt->fetchColumn() !== false;
    }
};
