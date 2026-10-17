<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

use App\Application\Services\EmailProviderService;
use App\Infrastructure\Email\EmailMessage;

/**
 * Email through the organization's own provider account (Sender or
 * Brevo) via EmailProviderService — one message per recipient, so no
 * recipient ever sees another's address.
 *
 * Marketing messages carry List-Unsubscribe (+ one-click POST, RFC 8058)
 * pointing at our unsubscribe link. The provider is the campaign's;
 * nothing here is provider-specific.
 */
final class EmailChannel implements MarketingChannelInterface
{
    public function __construct(private readonly EmailProviderService $email)
    {
    }

    public function key(): string
    {
        return 'email';
    }

    public function moduleKey(): string
    {
        return 'marketing_email';
    }

    public function unavailableReason(array $campaign): ?string
    {
        $provider = (string) ($campaign['provider'] ?? '');
        if ($this->email->definition($provider) === null) {
            return 'marketing.error.email_provider_missing';
        }

        return $this->email->connectedAdapter($provider) === null || $this->email->senderIdentity($provider) === null
            ? 'marketing.error.email_not_configured'
            : null;
    }

    public function send(array $campaign, string $address, string $recipientName, array $content, int $dedupeId, ?int $billedUnits = null, ?\Closure $onBilling = null): DispatchResult
    {
        $provider = (string) $campaign['provider'];
        $adapter = $this->email->connectedAdapter($provider);
        $identity = $this->email->senderIdentity($provider);
        if ($adapter === null || $identity === null) {
            return new DispatchResult(false, $provider, null, 'Email provider is not connected.');
        }

        $marketing = ($campaign['message_class'] ?? 'marketing') === EmailMessage::CLASS_MARKETING;
        $headers = [];
        if ($marketing && $content['unsubscribe_url'] !== '') {
            $headers['List-Unsubscribe'] = '<' . $content['unsubscribe_url'] . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        $result = $adapter->send(new EmailMessage(
            $address,
            $recipientName,
            $identity['email'],
            $identity['name'],
            $content['subject'],
            $content['html'],
            $content['text'],
            $identity['reply_to'],
            $headers,
            ['crm-campaign-' . (int) $campaign['id'], $marketing ? 'marketing' : 'transactional'],
            $marketing ? EmailMessage::CLASS_MARKETING : EmailMessage::CLASS_TRANSACTIONAL,
        ));

        return new DispatchResult(
            $result->success,
            $provider,
            $result->messageId,
            $result->errorMessage,
            $result->retryable,
            $result->retryAfterSeconds,
            $result->ambiguous,
        );
    }
}
