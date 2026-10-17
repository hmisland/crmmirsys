<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging;

/**
 * Extra provider cards on Organization settings → Messaging providers
 * (/org/messaging), added by modules that bring their own messaging
 * provider (e.g. MarketingBale → Bale/Safir). Each renderer returns the
 * card's HTML for the current user; the module owns the card, its
 * routes and its server-side checks. A failing renderer is skipped.
 */
final class MessagingSettingsCards
{
    /** @var array<string, \Closure(array): string> */
    private array $cards = [];

    /** @param \Closure(array $user): string $render */
    public function register(string $id, \Closure $render): void
    {
        $this->cards[$id] = $render;
    }

    /** @return string[] rendered cards, in registration order */
    public function render(array $user): array
    {
        $html = [];
        foreach ($this->cards as $render) {
            try {
                $html[] = $render($user);
            } catch (\Throwable) {
                continue;
            }
        }

        return $html;
    }
}
