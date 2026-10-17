<?php

declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * One email to ONE recipient. Multi-recipient sends are always split
 * into one message per recipient, so no address is ever shown to
 * another recipient.
 */
final class EmailMessage
{
    public const CLASS_MARKETING = 'marketing';
    public const CLASS_TRANSACTIONAL = 'transactional';

    /**
     * @param array<string, string> $headers extra headers (List-Unsubscribe, X-...)
     * @param string[] $tags provider tags, when supported
     */
    public function __construct(
        public readonly string $toEmail,
        public readonly string $toName,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text = '',
        public readonly ?string $replyTo = null,
        public readonly array $headers = [],
        public readonly array $tags = [],
        public readonly string $classification = self::CLASS_MARKETING,
    ) {
    }
}
