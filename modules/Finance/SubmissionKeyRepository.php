<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\BaseRepository;
use App\Infrastructure\Repositories\OrganizationContext;

/**
 * finance_submission_keys (module migration 0023) — Stage 5.2, finding L1.
 *
 * claim() runs INSIDE the transaction that writes the financial record, before
 * any other lock (lock order: submission key → document → period → number
 * series). The UNIQUE (organization_id, scope, submission_key) key makes a
 * concurrent repeat wait for the first transaction and then fail with a
 * duplicate — never a second record. Explicit organization filter everywhere.
 */
final class SubmissionKeyRepository extends BaseRepository
{
    public const SCOPE_EXPENSE = 'expense';
    public const SCOPE_PAYMENT = 'payment';
    public const SCOPE_CREDIT_NOTE = 'credit_note';

    /** A well-formed key (32 lowercase hex characters) from the request, or null for a legacy request without one. */
    public static function keyFrom(array $input): ?string
    {
        $key = $input['submission_key'] ?? null;

        return is_string($key) && preg_match('/^[a-f0-9]{32}$/', $key) === 1 ? $key : null;
    }

    public static function newKey(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Claims $key for $scope. Returns true when this request owns it (go on and
     * write); false when the key was already used — then original() says what
     * the first request created.
     */
    public function claim(string $scope, string $key, int $userId): bool
    {
        try {
            $this->query(
                'INSERT INTO finance_submission_keys (organization_id, user_id, scope, submission_key) VALUES (:org_id, :user_id, :scope, :submission_key)',
                ['org_id' => OrganizationContext::id(), 'user_id' => $userId, 'scope' => $scope, 'submission_key' => $key]
            );

            return true;
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /** Points the claimed key at the record this request created (same transaction). */
    public function attach(string $scope, string $key, int $resourceId): void
    {
        $this->query(
            'UPDATE finance_submission_keys SET resource_id = :resource_id WHERE organization_id = :org_id AND scope = :scope AND submission_key = :submission_key',
            ['resource_id' => $resourceId, 'org_id' => OrganizationContext::id(), 'scope' => $scope, 'submission_key' => $key]
        );
    }

    /** The record the first request created with this key — only for the same user, in this organization. */
    public function original(string $scope, string $key, int $userId): ?int
    {
        $row = $this->findOne(
            'SELECT resource_id FROM finance_submission_keys
              WHERE organization_id = :org_id AND scope = :scope AND submission_key = :submission_key AND user_id = :user_id',
            ['org_id' => OrganizationContext::id(), 'scope' => $scope, 'submission_key' => $key, 'user_id' => $userId]
        );

        return $row !== null && $row['resource_id'] !== null ? (int) $row['resource_id'] : null;
    }
}
