<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin wrapper over PHP superglobals. Controllers/Services never touch
 * $_GET/$_POST/$_SERVER directly — everything flows through this object,
 * which is also what makes it possible to construct fake requests in tests.
 */
final class Request
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $server,
        private readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Support method spoofing for HTML forms (PUT/PATCH/DELETE via POST + _method).
        if ($method === 'POST' && isset($_POST['_method'])) {
            $method = strtoupper((string) $_POST['_method']);
        }

        $body = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $headers[$name] = $value;
            }
        }

        return new self($method, $path, $_GET, $body, $headers, $_SERVER, $_FILES);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Every query-string param as-is — for a gateway callback (e.g.
     * ZarinPal's Authority/Status), where the exact set of params is
     * defined by the gateway, not by us, so naming them one by one
     * with query()/input() would mean guessing.
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtoupper($name)] ?? $this->headers[$name] ?? $default;
    }

    public function isJson(): bool
    {
        return str_contains((string) $this->header('CONTENT-TYPE', ''), 'application/json')
            || str_contains((string) $this->header('ACCEPT', ''), 'application/json');
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * One uploaded file's $_FILES entry (name/type/tmp_name/error/size),
     * or null if that field wasn't submitted / no file was chosen.
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /**
     * For a multi-file field (e.g. <input type="file" name="attachments[]" multiple>),
     * PHP's native $_FILES shape for an array field is one array PER
     * PROPERTY (name/type/tmp_name/error/size), each indexed by file
     * position — not one array per file. This reshapes that into a
     * clean list of individual per-file arrays, each the same shape
     * file() already returns, skipping any slot with no file chosen.
     *
     * @return array<int, array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function files(string $key): array
    {
        $raw = $this->files[$key] ?? null;

        if (!is_array($raw) || !isset($raw['name']) || !is_array($raw['name'])) {
            return [];
        }

        $count = count($raw['name']);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $error = $raw['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $result[] = [
                'name' => $raw['name'][$i] ?? '',
                'type' => $raw['type'][$i] ?? '',
                'tmp_name' => $raw['tmp_name'][$i] ?? '',
                'error' => $error,
                'size' => $raw['size'][$i] ?? 0,
            ];
        }

        return $result;
    }
}
