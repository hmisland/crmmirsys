<?php

declare(strict_types=1);

namespace App\Infrastructure\PublicLinks;

/** Handlers per link purpose, registered by modules (e.g. CustomerSatisfaction → "cx_survey"). */
final class PublicLinkRegistry
{
    /** @var array<string, PublicLinkHandlerInterface> */
    private array $handlers = [];

    public function register(PublicLinkHandlerInterface $handler): void
    {
        $this->handlers[$handler->purpose()] = $handler;
    }

    public function get(string $purpose): ?PublicLinkHandlerInterface
    {
        return $this->handlers[$purpose] ?? null;
    }
}
