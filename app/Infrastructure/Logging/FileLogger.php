<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

/**
 * Writes one line per entry to storage/logs/app-YYYY-MM-DD.log.
 * Deliberately no external logging library — keeps Phase 1 dependency-free
 * for shared hosting; swap the LoggerInterface binding for something
 * richer later without touching call sites.
 *
 * Per Phase 0 §48: never log passwords, private keys, payment secrets,
 * or API secrets. This class defensively redacts a denylist of common
 * key names from the context array as a last line of defense — callers
 * (especially AuditLogger, added alongside RBAC in Phase 3) are still
 * responsible for not passing secrets in the first place.
 */
final class FileLogger implements LoggerInterface
{
    private const REDACTED = '***REDACTED***';

    /** @var string[] */
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'secret', 'api_key', 'apikey',
        'token', 'access_token', 'refresh_token', 'private_key',
        'card_number', 'cvv', 'card_cvv', 'gateway_credentials',
    ];

    public function __construct(private readonly string $logDirectory, private readonly string $minLevel = 'debug')
    {
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0755, true);
        }
    }

    public function debug(string $message, array $context = []): void
    {
        $this->write('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->write('critical', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        static $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3, 'critical' => 4];

        if (($levels[$level] ?? 0) < ($levels[$this->minLevel] ?? 0)) {
            return;
        }

        $safeContext = $this->redact($context);
        $line = sprintf(
            '[%s] %s: %s %s%s',
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $safeContext !== [] ? json_encode($safeContext, JSON_UNESCAPED_UNICODE) : '',
            PHP_EOL
        );

        $file = $this->logDirectory . '/app-' . date('Y-m-d') . '.log';
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private function redact(array $context): array
    {
        $result = [];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redact($value);
                continue;
            }

            $result[$key] = in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)
                ? self::REDACTED
                : $value;
        }

        return $result;
    }
}
