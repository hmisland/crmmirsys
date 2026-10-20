<?php

declare(strict_types=1);

namespace Modules\Marketing;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * marketing_transactional_messages: idempotency record + history of the
 * single messages other modules hand to Marketing through Core's
 * TransactionalDeliveryInterface. Every query carries an explicit
 * organization_id condition from OrganizationContext.
 */
final class TransactionalMessageRepository extends TenantScopedRepository
{
    protected function table(): string
    {
        return 'marketing_transactional_messages';
    }

    public function findByReference(string $reference): ?array
    {
        return $this->findOne(
            'SELECT * FROM marketing_transactional_messages WHERE organization_id = :org_id AND reference = :reference',
            ['org_id' => OrganizationContext::id(), 'reference' => $reference]
        );
    }

    /**
     * Inserts a new row; null when the reference already exists (another
     * run created it first — UNIQUE (organization_id, reference)).
     */
    public function create(array $fields): ?int
    {
        $fields['organization_id'] = OrganizationContext::id();
        try {
            return $this->insertGetId('marketing_transactional_messages', $fields);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Claims a row that is certainly not sent ("retry") for a new attempt.
     * The conditional UPDATE guarantees only one run wins.
     */
    public function claimRetry(int $id, string $token, string $lockUntil, array $fields): bool
    {
        return $this->query(
            'UPDATE marketing_transactional_messages SET status = \'processing\', lock_token = :token, locked_until = :lock_until, attempts = attempts + 1,
                channel = :channel, address = :address, contact_id = :contact_id, recipient_name = :recipient_name, purpose = :purpose,
                billed_units = :billed_units, error = NULL, error_key = NULL
             WHERE id = :id AND organization_id = :org_id AND status = \'retry\'',
            [
                'token' => $token, 'lock_until' => $lockUntil, 'id' => $id, 'org_id' => OrganizationContext::id(),
                'channel' => $fields['channel'], 'address' => $fields['address'], 'contact_id' => $fields['contact_id'],
                'recipient_name' => $fields['recipient_name'], 'purpose' => $fields['purpose'], 'billed_units' => $fields['billed_units'],
            ]
        )->rowCount() > 0;
    }

    /**
     * Writes a result. With a token only its holder can write; without
     * one ($token null) only a row still in "processing" changes (stale
     * recovery), so a late duplicate run changes nothing.
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

        return $this->update('marketing_transactional_messages', $fields, $where, $params) > 0;
    }

    /** Fields of a row that is not claimed by anyone (e.g. a "retry" row that is now opted out). */
    public function updateUnclaimed(int $id, array $fields): bool
    {
        return $this->update('marketing_transactional_messages', $fields, "id = :id AND organization_id = :org_id AND status = 'retry'", [
            'id' => $id, 'org_id' => OrganizationContext::id(),
        ]) > 0;
    }

    /** Records what this message has paid for while it is still claimed (lock token holder only). */
    public function setBilledUnits(int $id, string $token, ?int $units): void
    {
        $this->update('marketing_transactional_messages', ['billed_units' => $units], 'id = :id AND organization_id = :org_id AND lock_token = :lock', [
            'id' => $id, 'org_id' => OrganizationContext::id(), 'lock' => $token,
        ]);
    }

    /** Whether a contact id belongs to the CURRENT organization (callers' ids are never trusted blindly). */
    public function contactExists(int $contactId): bool
    {
        return $this->findOne('SELECT id FROM contacts WHERE id = :id AND organization_id = :org_id', ['id' => $contactId, 'org_id' => OrganizationContext::id()]) !== null;
    }

    // --- History page (Phase B) ----------------------------------------------------

    /** @param array{q?: string, status?: string, channel?: string, purpose?: string} $filters */
    public function page(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = $this->conditions($filters);

        return $this->findMany(
            'SELECT * FROM marketing_transactional_messages WHERE ' . $where . ' ORDER BY id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            $params
        );
    }

    public function count(array $filters): int
    {
        [$where, $params] = $this->conditions($filters);

        return (int) ($this->findOne('SELECT COUNT(*) AS c FROM marketing_transactional_messages WHERE ' . $where, $params)['c'] ?? 0);
    }

    /** @return string[] purposes used in this organization (for the filter) */
    public function purposes(): array
    {
        return array_column($this->findMany(
            'SELECT DISTINCT purpose FROM marketing_transactional_messages WHERE organization_id = :org_id ORDER BY purpose',
            ['org_id' => OrganizationContext::id()]
        ), 'purpose');
    }

    // --- Delivery reports (Phase B) --------------------------------------------------

    /** Sent SMS still waiting for a final delivery state (provider keeps status for 48 hours). */
    public function awaitingSmsReport(string $since, string $checkedBefore, int $limit): array
    {
        return $this->findMany(
            "SELECT id, provider, provider_message_id, address, contact_id FROM marketing_transactional_messages
             WHERE organization_id = :org_id AND channel = 'sms' AND status = 'sent' AND provider_message_id IS NOT NULL
             AND (delivery_status IS NULL OR delivery_status IN ('queued', 'sent'))
             AND sent_at >= :since AND (status_checked_at IS NULL OR status_checked_at < :checked_before)
             ORDER BY id LIMIT " . max(1, $limit),
            ['org_id' => OrganizationContext::id(), 'since' => $since, 'checked_before' => $checkedBefore]
        );
    }

    /** @return int[] organizations with transactional SMS sent since $since */
    public function organizationsWithSmsSince(string $since): array
    {
        return array_map('intval', array_column($this->findMany(
            "SELECT DISTINCT organization_id FROM marketing_transactional_messages WHERE channel = 'sms' AND status = 'sent' AND sent_at >= :since",
            ['since' => $since]
        ), 'organization_id'));
    }

    public function updateDelivery(int $id, array $fields): void
    {
        $this->update('marketing_transactional_messages', $fields, 'id = :id AND organization_id = :org_id', ['id' => $id, 'org_id' => OrganizationContext::id()]);
    }

    public function findByProviderMessageId(string $providerPrefix, string $messageId): ?array
    {
        return $this->findOne(
            'SELECT * FROM marketing_transactional_messages WHERE organization_id = :org_id AND provider_message_id = :mid AND provider LIKE :provider ORDER BY id DESC LIMIT 1',
            ['org_id' => OrganizationContext::id(), 'mid' => $messageId, 'provider' => $providerPrefix . '%']
        );
    }

    /** @return array{0: string, 1: array} */
    private function conditions(array $filters): array
    {
        $where = 'organization_id = :org_id';
        $params = ['org_id' => OrganizationContext::id()];
        foreach (['status', 'channel', 'purpose'] as $key) {
            if (!empty($filters[$key])) {
                $where .= " AND {$key} = :{$key}";
                $params[$key] = (string) $filters[$key];
            }
        }
        if (!empty($filters['q'])) {
            $where .= ' AND (address LIKE :q1 OR recipient_name LIKE :q2)';
            $params['q1'] = $params['q2'] = '%' . $filters['q'] . '%';
        }

        return [$where, $params];
    }
}
