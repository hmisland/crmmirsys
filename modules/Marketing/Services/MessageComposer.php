<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Infrastructure\Localization\Translator;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\Marketing\AudienceRepository;

/**
 * Renders a campaign for one recipient: personalization, and for
 * marketing email an unsubscribe link (added as a footer when the body
 * doesn't place {{unsubscribe_url}} itself). The footer text is in the
 * organization's default language — the worker has no user locale.
 */
final class MessageComposer
{
    /** @var array<int, array{name: string, translator: Translator}> */
    private array $orgCache = [];

    public function __construct(
        private readonly AudienceRepository $audience,
        private readonly UnsubscribeLinks $links,
        private readonly string $langPath,
        private readonly string $installationSeed,
    ) {
    }

    /** @return array{subject: string, text: string, html: string, unsubscribe_url: string} */
    public function compose(array $campaign, array $message, ?string $unsubscribeOverride = null, bool $placeholders = false): array
    {
        $organizationId = OrganizationContext::id();
        $org = $this->organization($organizationId);
        $contact = !empty($message['contact_id']) ? ($this->audience->contactForPersonalization((int) $message['contact_id']) ?? []) : [];
        $contact['recipient_name'] = $message['recipient_name'] ?? '';

        $isEmail = ($campaign['channel'] ?? '') === 'email';
        $marketing = ($campaign['message_class'] ?? 'marketing') === 'marketing';
        $unsubscribe = !$isEmail || !$marketing ? '' : ($unsubscribeOverride ?? (!empty($message['id']) ? $this->links->url($organizationId, (int) $message['id']) : ''));
        $vars = Personalizer::varsFor($contact, $org['name'], $unsubscribe);
        if ($placeholders) {
            // No contact data: show which variable goes where, e.g. [first_name].
            foreach (['first_name', 'last_name', 'full_name', 'company', 'email', 'phone'] as $name) {
                $vars[$name] = '[' . $name . ']';
            }
        }

        if (!$isEmail) {
            return ['subject' => '', 'text' => trim(Personalizer::render((string) $campaign['body_text'], $vars)), 'html' => '', 'unsubscribe_url' => ''];
        }

        $htmlTemplate = (string) ($campaign['body_html'] ?? '');
        $html = Personalizer::render($htmlTemplate, $vars, true);
        $text = trim((string) ($campaign['body_text'] ?? '')) !== ''
            ? Personalizer::render((string) $campaign['body_text'], $vars)
            : trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html)), ENT_QUOTES, 'UTF-8'));

        if ($unsubscribe !== '' && !Personalizer::uses($htmlTemplate, 'unsubscribe_url')) {
            $label = htmlspecialchars($org['translator']->get('marketing.unsubscribe_footer'), ENT_QUOTES, 'UTF-8');
            $html .= '<p style="font-size:12px;color:#6b7280;margin-top:24px"><a href="' . htmlspecialchars($unsubscribe, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a></p>';
            $text .= "\n\n" . $org['translator']->get('marketing.unsubscribe_footer') . ': ' . $unsubscribe;
        }

        return [
            'subject' => Personalizer::render((string) $campaign['subject'], $vars),
            'text' => $text,
            'html' => $html,
            'unsubscribe_url' => $unsubscribe,
        ];
    }

    /**
     * Preview for a sample recipient (no real message id → placeholder
     * unsubscribe link). $masked: no contact data at all — variables are
     * shown as [first_name] etc. (viewer without contacts.view).
     */
    public function preview(array $campaign, ?array $sample, bool $masked = false): array
    {
        $message = $masked
            ? ['id' => 0, 'contact_id' => null, 'recipient_name' => '']
            : ['id' => 0, 'contact_id' => $sample['contact_id'] ?? null, 'recipient_name' => $sample['recipient_name'] ?? ''];

        return $this->compose($campaign, $message, '#', $masked);
    }

    /**
     * Provider-side dedupe id (Kavenegar localid) for a message row:
     * an installation prefix keeps two installations sharing one
     * provider account (e.g. staging and production) from colliding.
     */
    public function dedupeId(int $messageId): int
    {
        $prefix = 1_000_000 + (crc32($this->installationSeed) % 8_000_000);

        return $prefix * 1_000_000_000_000 + $messageId;
    }

    /** @return array{name: string, translator: Translator} */
    private function organization(int $organizationId): array
    {
        if (!isset($this->orgCache[$organizationId])) {
            $language = $this->audience->organizationLanguage();
            $this->orgCache[$organizationId] = [
                'name' => $this->audience->organizationName(),
                'translator' => new Translator(in_array($language, Translator::SUPPORTED_LOCALES, true) ? $language : 'en', $this->langPath),
            ];
        }

        return $this->orgCache[$organizationId];
    }
}
