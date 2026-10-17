<?php

declare(strict_types=1);

namespace Modules\MarketingBale\Safir;

/**
 * HTTPS POST with cURL: short connect timeout, bounded total time, no
 * redirects, HTTPS only, and at most MAX_BODY bytes of response kept.
 * Nothing is logged here (the request carries the api-access-key and
 * the message text).
 */
final class CurlSafirTransport implements SafirTransport
{
    public const MAX_BODY = 65536;

    /** cURL errors raised before any byte of the request left this server. */
    private const NOT_SENT_ERRORS = [
        5,  // CURLE_COULDNT_RESOLVE_PROXY
        6,  // CURLE_COULDNT_RESOLVE_HOST
        7,  // CURLE_COULDNT_CONNECT
        35, // CURLE_SSL_CONNECT_ERROR
        51, // CURLE_PEER_FAILED_VERIFICATION
        60, // CURLE_SSL_CACERT
        77, // CURLE_SSL_CACERT_BADFILE
    ];

    public function __construct(
        private readonly int $connectTimeoutSeconds = 5,
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function post(string $url, array $headers, string $body): SafirHttpResponse
    {
        if (!str_starts_with($url, 'https://')) {
            return SafirHttpResponse::notSent(1);
        }

        $received = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$received): int {
                if (strlen($received) < self::MAX_BODY) {
                    $received .= substr($chunk, 0, self::MAX_BODY - strlen($received));
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($ok === false || $errno !== 0) {
            return in_array($errno, self::NOT_SENT_ERRORS, true) ? SafirHttpResponse::notSent($errno) : SafirHttpResponse::lost($errno);
        }

        return new SafirHttpResponse($status > 0 ? $status : null, $received);
    }
}
