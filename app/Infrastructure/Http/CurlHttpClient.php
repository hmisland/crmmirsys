<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

/** cURL transport with TLS verification and short timeouts. */
final class CurlHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSeconds = 15): HttpResponse
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($result === false) {
            // 6 = can't resolve host, 7 = can't connect: nothing was sent.
            $connectFailed = in_array($errno, [6, 7], true);

            return new HttpResponse(0, '', [], $error !== '' ? $error : 'Network error', $connectFailed, $errno === 28);
        }

        return new HttpResponse($status, (string) $result, $responseHeaders);
    }
}
