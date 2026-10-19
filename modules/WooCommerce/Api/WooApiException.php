<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Api;

/** A WooCommerce API call failed. $errorKey is a translation key (no response body, no credentials); $retryable: temporary. */
final class WooApiException extends \RuntimeException
{
    public function __construct(public readonly string $errorKey, public readonly bool $retryable)
    {
        parent::__construct($errorKey);
    }
}
