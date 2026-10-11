<?php

declare(strict_types=1);

namespace Modules\Finance;

/**
 * Thrown inside a write transaction when its submission key was already
 * claimed (Stage 5.2, L1): the transaction is unwound and the caller answers
 * with the record the first request created. Never shown to users.
 */
final class DuplicateSubmission extends \RuntimeException
{
}
