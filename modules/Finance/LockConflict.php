<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Core\Exceptions\ValidationException;

use function App\Infrastructure\Theme\t;

/**
 * Stage 5.2 (L5): a financial write that loses a lock race — InnoDB deadlock
 * (1213) or lock-wait timeout (1205) — has been rolled back as a whole by the
 * database / BaseRepository::transaction(). This turns that into a clear
 * "please retry" validation answer instead of a 500. No automatic retry.
 */
final class LockConflict
{
    private const CODES = [1213, 1205];

    public static function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (in_array((int) ($e->errorInfo[1] ?? 0), self::CODES, true)) {
                throw new ValidationException(['retry' => [t('finance.error.lock_retry')]]);
            }
            throw $e;
        }
    }
}
