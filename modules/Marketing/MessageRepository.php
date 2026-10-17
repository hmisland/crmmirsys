<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * marketing_messages: per-recipient history and the send queue.
 * Every query carries an explicit organization_id condition from
 * OrganizationContext (the worker runs each campaign inside
 * OrganizationContext::runAs()).
 */
final class MessageRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_messages';
    }

    /**
     * Snapshot of recipients. INSERT IGNORE + UNIQUE (campaign_id,
     * channel, address) means a repeated call never adds a duplicate.
     *
     * @param array<int, array{contact_id: ?int, address: string, recipient_name: string, status?: string, skip_reason?: ?string}> $rows
     * @return int rows inserted
     */
    public function insertRecipients(int $campaignId, string $channel, array $rows): int
    {
        $inserted = 0;
        foreach (array_chunk($rows, 300) as $chunk) {
            $values = [];
            $params = [];
            // Native prepared statements: every placeholder name must be unique.
            foreach ($chunk as $i => $row) {
                $values[] = "(:org{$i}, :campaign{$i}, :contact{$i}, :channel{$i}, :address{$i}, :name{$i}, :status{$i}, :skip{$i})";
                $params['org' . $i] = OrganizationContext::id();
                $params['campaign' . $i] = $campaignId;
                $params['channel' . $i] = $channel;
                $params['contact' . $i] = $row['contact_id'];
                $params['address' . $i] = $row['address'];
                $params['name' . $i] = mb_substr($row['recipient_name'], 0, 200);
                $params['status' . $i] = $row['status'] ?? 'pending';
                $params['skip' . $i] = $row['skip_reason'] ?? null;
            }

            $inserted += $this->query(
                'INSERT IGNORE INTO marketing_messages (organization_id, campaign_id, contact_id, channel, address, recipient_name, status, skip_reason) VALUES ' . implode(', ', $values),
                $params
            )->rowCount();
        }

        return $inserted;
    }

    /** Runs $work in a transaction (joins the caller's, if one is open). */
    public function atomically(callable $work): mixed
    {
        return $this->pdo()->inTransaction() ? $work() : $this->transaction($work);
    }

    public function deleteForCampaign(int $campaignId): void
    {
        $this->delete('marketing_messages', 'campaign_id = :campaign_id AND organization_id = :org_id', ['campaign_id' => $campaignId, 'org_id' => OrganizationContext::id()]);
    }

    public function findById(int $id): ?array
    {
        return $this->findOne('SELECT * FROM marketing_messages WHERE id = :id AND organization_id = :org_id', ['id' => $id, 'org_id' => OrganizationContext::id()]);
    }

    /**
     * unknown = failed rows whose outcome is ambiguous (may have reached the provider);
     * unsupported = skipped because the channel cannot serve the recipient.
     *
     * @return array{status: array<string, int>, delivery: array<string, int>, total: int, unknown: int, unsupported: int}
     */
    public function countsForCampaign(int $campaignId): array
    {
        $params = ['campaign_id' => $campaignId, 'org_id' => OrganizationContext::id()];
        $status = [];
        $total = 0;
        foreach ($this->findMany('SELECT status, COUNT(*) AS c FROM marketing_messages WHERE campaign_id = :campaign_id AND organization_id = :org_id GROUP BY status', $params) as $row) {
            $status[(string) $row['status']] = (int) $row['c'];
            $total += (int) $row['c'];
        }
        $delivery = [];
        foreach ($this->findMany('SELECT delivery_status, COUNT(*) AS c FROM marketing_messages WHERE campaign_id = :campaign_id AND organization_id = :org_id AND delivery_status IS NOT NULL GROUP BY delivery_status', $params) as $row) {
            $delivery[(string) $row['delivery_status']] = (int) $row['c'];
        }

        $extra = $this->findOne(
            "SELECT SUM(status = 'failed' AND ambiguous = 1) AS unknown, SUM(status = 'skipped' AND skip_reason = 'unsupported_recipient') AS unsupported
             FROM marketing_messages WHERE campaign_id = :campaign_id AND organization_id = :org_id",
            $params
        ) ?? [];

        return ['status' => $status, 'delivery' => $delivery, 'total' => $total, 'unknown' => (int) ($extra['unknown'] ?? 0), 'unsupported' => (int) ($extra['unsupported'] ?? 0)];
    }

    /** @param array{campaign_id?: int, contact_id?: int, status?: string, channel?: string, q?: string} $filters */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany(
            'SELECT m.*, c.name AS campaign_name FROM marketing_messages m JOIN marketing_campaigns c ON c.id = m.campaign_id
             WHERE ' . $where . ' ORDER BY m.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM marketing_messages m WHERE ' . $where, $params)['c'] ?? 0);
    }

    // --- Queue ---------------------------------------------------------------

    /**
     * Claims up to $limit due rows of one campaign for this worker: the
     * conditional UPDATE (status still 'pending') guarantees a row is
     * claimed by exactly one worker, even with two running at once.
     */
    public function claim(int $campaignId, int $limit, string $token, string $now, string $lockUntil): array
    {
        $params = ['campaign_id' => $campaignId, 'org_id' => OrganizationContext::id(), 'now' => $now];
        $ids = array_map('intval', array_column($this->findMany(
            "SELECT id FROM marketing_messages WHERE campaign_id = :campaign_id AND organization_id = :org_id AND status = 'pending'
             AND (next_attempt_at IS NULL OR next_attempt_at <= :now) ORDER BY id LIMIT " . max(1, $limit),
            $params
        ), 'id'));
        if ($ids === []) {
            return [];
        }

        $this->query(
            "UPDATE marketing_messages SET status = 'processing', lock_token = :token, locked_until = :lock_until, attempts = attempts + 1
             WHERE id IN (" . implode(',', $ids) . ") AND organization_id = :org_id AND status = 'pending'",
            ['token' => $token, 'lock_until' => $lockUntil, 'org_id' => OrganizationContext::id()]
        );

        return $this->findMany(
            'SELECT * FROM marketing_messages WHERE lock_token = :token AND organization_id = :org_id AND status = \'processing\' ORDER BY id',
            ['token' => $token, 'org_id' => OrganizationContext::id()]
        );
    }

    /** Rows whose worker died mid-send (lock expired). */
    public function staleProcessing(int $campaignId, string $now): array
    {
        return $this->findMany(
            "SELECT * FROM marketing_messages WHERE campaign_id = :campaign_id AND organization_id = :org_id AND status = 'processing' AND locked_until < :now",
            ['campaign_id' => $campaignId, 'org_id' => OrganizationContext::id(), 'now' => $now]
        );
    }

    /**
     * Finishes a claimed row. Only the holder of the lock token can
     * write the result — a late duplicate worker changes nothing.
     */
    public function finish(int $id, ?string $token, array $fields): bool
    {
        $fields['lock_token'] = null;
        $fields['locked_until'] = null;
        $where = 'id = :id AND organization_id = :org_id';
        $params = ['id' => $id, 'org_id' => OrganizationContext::id()];
        if ($token !== null) {
            $where .= ' AND lock_token = :lock';
            $params['lock'] = $token;
        } else {
            $where .= " AND status = 'processing'";
        }

        return $this->update('marketing_messages', $fields, $where, $params) > 0;
    }

    /** Records what this message has paid for while it is still claimed (lock token holder only). */
    public function setBilledUnits(int $id, string $token, ?int $units): void
    {
        $this->update('marketing_messages', ['billed_units' => $units], 'id = :id AND organization_id = :org_id AND lock_token = :lock', [
            'id' => $id, 'org_id' => OrganizationContext::id(), 'lock' => $token,
        ]);
    }

    public function pendingCount(int $campaignId): int
    {
        return (int) ($this->findOne(
            "SELECT COUNT(*) AS c FROM marketing_messages WHERE campaign_id = :campaign_id AND organization_id = :org_id AND status IN ('pending', 'processing')",
            ['campaign_id' => $campaignId, 'org_id' => OrganizationContext::id()]
        )['c'] ?? 0);
    }

    public function cancelPending(int $campaignId): int
    {
        return $this->update('marketing_messages', ['status' => 'cancelled'], "campaign_id = :campaign_id AND organization_id = :org_id AND status = 'pending'", [
            'campaign_id' => $campaignId, 'org_id' => OrganizationContext::id(),
        ]);
    }

    /** Failed rows that certainly did not reach the provider go back to the queue. */
    public function requeueFailed(int $campaignId): int
    {
        return $this->update(
            'marketing_messages',
            ['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => null, 'error' => null],
            "campaign_id = :campaign_id AND organization_id = :org_id AND status = 'failed' AND ambiguous = 0",
            ['campaign_id' => $campaignId, 'org_id' => OrganizationContext::id()]
        );
    }

    /**
     * One failed + ambiguous row back to the queue (explicitly confirmed by a
     * user; the row keeps its id, so the provider dedupe id is the same).
     */
    public function requeueUnknown(int $campaignId, int $messageId): bool
    {
        return $this->update(
            'marketing_messages',
            ['status' => 'pending', 'attempts' => 0, 'ambiguous' => 0, 'next_attempt_at' => null, 'error' => null],
            "id = :id AND campaign_id = :campaign_id AND organization_id = :org_id AND status = 'failed' AND ambiguous = 1",
            ['id' => $messageId, 'campaign_id' => $campaignId, 'org_id' => OrganizationContext::id()]
        ) > 0;
    }

    // --- Delivery reports ----------------------------------------------------

    /** Sent SMS still waiting for a final delivery state (provider keeps status for 48 hours). */
    public function awaitingSmsReport(string $since, string $checkedBefore, int $limit): array
    {
        return $this->findMany(
            "SELECT id, provider, provider_message_id, address, contact_id FROM marketing_messages
             WHERE organization_id = :org_id AND channel = 'sms' AND status = 'sent' AND provider_message_id IS NOT NULL
             AND (delivery_status IS NULL OR delivery_status IN ('queued', 'sent'))
             AND sent_at >= :since AND (status_checked_at IS NULL OR status_checked_at < :checked_before)
             ORDER BY id LIMIT " . max(1, $limit),
            ['org_id' => OrganizationContext::id(), 'since' => $since, 'checked_before' => $checkedBefore]
        );
    }

    public function updateDelivery(int $id, array $fields): void
    {
        $this->update('marketing_messages', $fields, 'id = :id AND organization_id = :org_id', ['id' => $id, 'org_id' => OrganizationContext::id()]);
    }

    public function findByProviderMessageId(string $providerPrefix, string $messageId): ?array
    {
        return $this->findOne(
            'SELECT * FROM marketing_messages WHERE organization_id = :org_id AND provider_message_id = :mid AND provider LIKE :provider ORDER BY id DESC LIMIT 1',
            ['org_id' => OrganizationContext::id(), 'mid' => $messageId, 'provider' => $providerPrefix . '%']
        );
    }

    /** @return array<int, int> organizations with SMS sent since $since */
    public function organizationsWithSmsSince(string $since): array
    {
        return array_map('intval', array_column($this->findMany(
            "SELECT DISTINCT organization_id FROM marketing_messages WHERE channel = 'sms' AND status = 'sent' AND sent_at >= :since",
            ['since' => $since]
        ), 'organization_id'));
    }

    // --- Analytics -----------------------------------------------------------

    /**
     * created_at is stamped by MySQL, so windows are computed on the database
     * clock (PHP's time zone may differ).
     *
     * @return array<string, array<string, int>> channel => [sent, failed, delivered, bounced, skipped] in the last $days days
     */
    public function statsLastDays(int $days): array
    {
        $rows = $this->findMany(
            "SELECT channel,
                    SUM(status = 'sent') AS sent,
                    SUM(status = 'failed') AS failed,
                    SUM(status = 'skipped') AS skipped,
                    SUM(delivery_status = 'delivered') AS delivered,
                    SUM(delivery_status IN ('bounced', 'undelivered', 'blocked', 'invalid', 'failed')) AS bounced
             FROM marketing_messages WHERE organization_id = :org_id AND created_at >= NOW() - INTERVAL :days DAY GROUP BY channel",
            ['org_id' => OrganizationContext::id(), 'days' => max(1, $days)]
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(string) $row['channel']] = array_map('intval', array_intersect_key($row, array_flip(['sent', 'failed', 'skipped', 'delivered', 'bounced'])));
        }

        return $stats;
    }

    /** A pending row older than $minutes (database clock) — used to warn when no worker is running. */
    public function hasPendingOlderThan(int $minutes): bool
    {
        return $this->findOne(
            "SELECT id FROM marketing_messages WHERE organization_id = :org_id AND status = 'pending' AND created_at < NOW() - INTERVAL :minutes MINUTE LIMIT 1",
            ['org_id' => OrganizationContext::id(), 'minutes' => max(1, $minutes)]
        ) !== null;
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = 'm.organization_id = :org_id';
        $params = ['org_id' => OrganizationContext::id()];

        foreach (['campaign_id', 'contact_id'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND m.{$key} = :{$key}";
                $params[$key] = (int) $filters[$key];
            }
        }
        foreach (['status', 'channel'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND m.{$key} = :{$key}";
                $params[$key] = (string) $filters[$key];
            }
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (m.address LIKE :q1 OR m.recipient_name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $filters['q'] . '%';
        }

        return [$where, $params];
    }
}
