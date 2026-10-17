<?php

declare(strict_types=1);

namespace Modules\Marketing\Channels;

/**
 * The channels Marketing can send on, keyed by channel key.
 *
 * Channels added by other modules (e.g. MarketingBale) register here
 * through MarketingModule::services(). Phone-text channels are also
 * remembered statically, because address normalization
 * (AudienceResolver::normalize) is static and used where no registry
 * instance is at hand (manual opt-outs, test sends).
 */
final class ChannelRegistry
{
    /** Built-in channels; the order of keys() puts them first. */
    public const BUILT_IN = ['sms', 'email'];

    /** @var array<string, MarketingChannelInterface> */
    private array $channels = [];

    /** @var array<string, PhoneTextChannel> */
    private static array $phoneChannels = [];

    public function register(MarketingChannelInterface $channel): void
    {
        $this->channels[$channel->key()] = $channel;
        if ($channel instanceof PhoneTextChannel && !in_array($channel->key(), self::BUILT_IN, true)) {
            self::$phoneChannels[$channel->key()] = $channel;
        }
    }

    public function get(string $key): ?MarketingChannelInterface
    {
        return $this->channels[$key] ?? null;
    }

    /** @return string[] */
    public function keys(): array
    {
        return array_keys($this->channels);
    }

    /** A registered phone-text channel (e.g. "bale"), or null for sms/email/unknown keys. */
    public static function phoneChannel(string $key): ?PhoneTextChannel
    {
        return self::$phoneChannels[$key] ?? null;
    }

    /** @return string[] keys of the additional (non built-in) channels installed, for filters and pickers */
    public static function extraChannelKeys(): array
    {
        return array_keys(self::$phoneChannels);
    }
}
