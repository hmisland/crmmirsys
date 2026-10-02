<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        $response = new self($status, $body);
        $response->headers['Content-Type'] = 'text/html; charset=utf-8';

        return $response;
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $response = new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $response->headers['Content-Type'] = 'application/json; charset=utf-8';

        return $response;
    }

    public static function redirect(string $to, int $status = 302): self
    {
        $response = new self($status, '');
        $response->headers['Location'] = $to;

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $this->body;
    }
}
