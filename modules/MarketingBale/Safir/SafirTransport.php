<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Safir;

/**
 * The only way SafirClient reaches the network. Tests inject a fake;
 * production uses CurlSafirTransport.
 */
interface SafirTransport
{
    /** @param array<int, string> $headers */
    public function post(string $url, array $headers, string $body): SafirHttpResponse;
}
