<?php

declare(strict_types=1);

namespace App\Infrastructure\ErrorHandling;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Theme\TemplateEngine;

/**
 * Per Phase 0 §32/§61: production users must never see SQL errors,
 * stack traces, file paths, or secrets. Every uncaught throwable is
 * logged in full server-side and converted into either a themed HTML
 * error page (web requests) or a consistent JSON error envelope (API
 * requests / any request declaring Accept: application/json).
 */
final class ErrorHandler
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TemplateEngine $templates,
        private readonly bool $debug,
    ) {
    }

    public function register(): void
    {
        set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) {
            if (!(error_reporting() & $severity)) {
                return false;
            }

            // Only genuinely fatal-for-the-request severities abort
            // execution. E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR
            // never reach a custom error handler at all (PHP routes
            // those straight past it — the shutdown function below is
            // what catches those); E_USER_ERROR and
            // E_RECOVERABLE_ERROR are the only ones a handler like
            // this can meaningfully see and that genuinely mean "this
            // can't continue."
            //
            // Everything else — E_WARNING, E_NOTICE, E_DEPRECATED,
            // E_STRICT, and their E_USER_* equivalents — is logged and
            // execution continues. A stray PHP warning (e.g. a
            // "use statement with non-compound name has no effect"
            // notice, or a future deprecation notice from a newer PHP
            // version) must never turn into a fatal 500 for an end
            // user — that was a real production incident this
            // over-eager version of the handler caused.
            $fatal = E_USER_ERROR | E_RECOVERABLE_ERROR;

            if ($severity & $fatal) {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }

            $this->logger->warning($message, ['severity' => $severity, 'file' => $file, 'line' => $line]);

            return true;
        });

        register_shutdown_function(function () {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $this->logger->critical('Fatal error', $error);
                if (!headers_sent()) {
                    $this->render(new Request('GET', '/', [], [], [], []), 500)->send();
                }
            }
        });
    }

    public function handle(\Throwable $e, Request $request): Response
    {
        $status = $e instanceof HttpException ? $e->statusCode : 500;

        $logMethod = $status >= 500 ? 'error' : 'warning';
        $this->logger->{$logMethod}($e->getMessage(), [
            'exception' => $e::class,
            'status' => $status,
            'path' => $request->path,
            'method' => $request->method,
            'trace' => $this->debug ? $e->getTraceAsString() : null,
        ]);

        return $this->render($request, $status, $e);
    }

    private function render(Request $request, int $status, ?\Throwable $e = null): Response
    {
        if ($request->isJson()) {
            $payload = [
                'errors' => [[
                    'status' => (string) $status,
                    'code' => $e instanceof HttpException ? $e->errorCode : 'server_error',
                    'title' => $status >= 500 ? 'Internal server error.' : ($e?->getMessage() ?: 'Request error.'),
                ]],
            ];

            if ($e instanceof \App\Core\Exceptions\ValidationException) {
                $payload['errors'][0]['meta'] = ['fields' => $e->errors];
            }

            return Response::json($payload, $status);
        }

        $view = $status === 404 ? 'errors/404' : 'errors/500';
        $message = $status >= 500 || !$e ? null : $e->getMessage();

        return Response::html($this->templates->render($view, ['message' => $message]), $status);
    }
}
