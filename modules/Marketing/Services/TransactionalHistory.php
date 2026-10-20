<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Core\Exceptions\AuthorizationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use Modules\Marketing\MarketingPermission;
use Modules\Marketing\TransactionalMessageRepository;

/**
 * Phase B: Marketing → Transactional (read-only, marketing.view) — the
 * single messages other modules sent through Marketing (e.g. survey
 * invitations), from marketing_transactional_messages.
 */
final class TransactionalHistory
{
    public function __construct(
        private readonly TransactionalMessageRepository $messages,
        private readonly AuthorizationEngine $authorization,
    ) {
    }

    public function page(array $user, array $filters, int $limit, int $offset): array
    {
        $this->authorize($user);

        return $this->messages->page($filters, $limit, $offset);
    }

    public function count(array $user, array $filters): int
    {
        $this->authorize($user);

        return $this->messages->count($filters);
    }

    /** @return string[] */
    public function purposes(array $user): array
    {
        $this->authorize($user);

        return $this->messages->purposes();
    }

    private function authorize(array $user): void
    {
        if (!$this->authorization->can($user, MarketingPermission::VIEW)) {
            throw new AuthorizationException();
        }
    }
}
