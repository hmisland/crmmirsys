<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

use App\Application\Services\SmsService;
use Modules\Marketing\AudienceRepository;
use Modules\Marketing\Channels\ChannelRegistry;
use Modules\Marketing\SuppressionRepository;

/**
 * Turns an audience (selected contacts, a contact group, a tag, or all
 * contacts) into recipients for one channel, classifying each contact:
 *
 *  - eligible: valid, not suppressed, first time this address appears;
 *  - missing_address / invalid_address;
 *  - suppressed (opt-out / bounce / complaint / block / manual);
 *  - duplicate (another contact already has this address).
 *
 * Marketing messages honour every suppression; transactional messages
 * ignore "unsubscribed" (an opt-out from marketing) but keep the rest.
 */
final class AudienceResolver
{
    public const TYPES = ['contacts', 'group', 'tag', 'all', 'segment'];
    public const MAX_SELECTED_CONTACTS = 5000;

    public function __construct(
        private readonly AudienceRepository $audience,
        private readonly SuppressionRepository $suppressions,
        private readonly ?\Modules\Marketing\SegmentRepository $segments = null,
    ) {
    }

    /**
     * Ownership check of the selection: every selected contact, group or
     * tag must belong to the current organization.
     *
     * @param int[] $ids
     * @return string|null translation key of the problem
     */
    public function validate(string $type, array $ids): ?string
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));

        return match ($type) {
            'contacts' => match (true) {
                $ids === [] => 'marketing.error.no_contacts_selected',
                count($ids) > self::MAX_SELECTED_CONTACTS => 'marketing.error.too_many_selected',
                $this->audience->countContactsInOrganization($ids) !== count($ids) => 'marketing.error.invalid_selection',
                default => null,
            },
            'group' => isset($ids[0]) && $this->audience->groupExists($ids[0]) ? null : 'marketing.error.invalid_group',
            'tag' => isset($ids[0]) && $this->audience->tagExists($ids[0]) ? null : 'marketing.error.invalid_tag',
            'all' => null,
            'segment' => isset($ids[0]) && $this->segments?->find($ids[0]) !== null ? null : 'marketing.error.segment_missing',
            default => 'marketing.error.invalid_audience',
        };
    }

    /**
     * Walks the whole audience in chunks, calling $onChunk with the
     * classified rows of each chunk; returns the totals.
     *
     * Resumable: $afterId starts after a contact id and $maxChunks stops
     * early; the result carries last_id and done, so a background worker
     * can expand a very large audience across several runs. Duplicate
     * addresses across runs are still stopped by the UNIQUE index.
     *
     * @param int[] $ids
     * @param (callable(array<int, array<string, mixed>>): void)|null $onChunk
     * @return array{contacts: int, eligible: int, missing_address: int, invalid_address: int, unsupported: int, suppressed: int, duplicate: int, sample: ?array, last_id: int, done: bool}
     */
    public function walk(string $channel, string $messageClass, string $type, array $ids, ?callable $onChunk = null, int $afterId = 0, ?int $maxChunks = null, ?array $snapshot = null): array
    {
        $totals = ['contacts' => 0, 'eligible' => 0, 'missing_address' => 0, 'invalid_address' => 0, 'unsupported' => 0, 'suppressed' => 0, 'duplicate' => 0, 'sample' => null, 'last_id' => $afterId, 'done' => false];
        $seen = [];
        $after = $afterId;
        $phoneChannel = self::isPhoneChannel($channel);
        $chunks = 0;

        do {
            $contacts = $this->audience->chunk($channel, $type, $ids, $after, $snapshot);
            if ($contacts === []) {
                $totals['done'] = true;
                break;
            }
            $after = (int) end($contacts)['id'];
            $totals['last_id'] = $after;
            $chunks++;

            $rows = [];
            foreach ($contacts as $contact) {
                $totals['contacts']++;
                $raw = trim((string) ($phoneChannel ? ($contact['phone'] ?? '') : ($contact['email'] ?? '')));
                if ($raw === '') {
                    $raw = trim((string) ($contact['alt_address'] ?? ''));
                }
                $name = trim((string) $contact['first_name'] . ' ' . (string) ($contact['last_name'] ?? ''));

                if ($raw === '') {
                    $totals['missing_address']++;
                    continue;
                }

                [$address, $invalidReason] = self::classify($channel, $raw);
                $row = ['contact_id' => (int) $contact['id'], 'recipient_name' => $name, 'contact' => $contact];
                if ($address === null) {
                    // A phone-text channel may report a valid number it cannot serve (e.g. Bale: non-Iranian).
                    $invalidReason = $invalidReason === 'unsupported_recipient' ? 'unsupported_recipient' : 'invalid_address';
                    $totals[$invalidReason === 'unsupported_recipient' ? 'unsupported' : 'invalid_address']++;
                    $rows[] = $row + ['address' => mb_substr($raw, 0, 255), 'status' => 'skipped', 'skip_reason' => $invalidReason];
                    continue;
                }
                if (isset($seen[$address])) {
                    $totals['duplicate']++;
                    continue;
                }
                $seen[$address] = true;
                $rows[] = $row + ['address' => $address, 'status' => 'pending', 'skip_reason' => null];
            }

            $reasons = $this->suppressions->reasonsFor($channel, array_column(array_filter($rows, fn ($r) => $r['status'] === 'pending'), 'address'));
            foreach ($rows as &$row) {
                if ($row['status'] !== 'pending') {
                    continue;
                }
                $reason = $reasons[$row['address']] ?? null;
                if ($reason !== null && ($messageClass === 'marketing' || $reason !== 'unsubscribed')) {
                    $row['status'] = 'skipped';
                    $row['skip_reason'] = 'suppressed_' . $reason;
                    $totals['suppressed']++;
                    continue;
                }
                $totals['eligible']++;
                $totals['sample'] ??= $row;
            }
            unset($row);

            if ($onChunk !== null && $rows !== []) {
                $onChunk($rows);
            }
            if (count($contacts) < AudienceRepository::CHUNK) {
                $totals['done'] = true;
                break;
            }
        } while ($maxChunks === null || $chunks < $maxChunks);

        return $totals;
    }

    /** Contacts the audience matches (one COUNT query). */
    public function count(string $type, array $ids, ?array $snapshot = null): int
    {
        return $this->audience->countMatching($type, $ids, $snapshot);
    }

    /** Whether the channel addresses contacts by mobile number (SMS, or a registered phone-text channel). */
    public static function isPhoneChannel(string $channel): bool
    {
        return $channel === 'sms' || ChannelRegistry::phoneChannel($channel) !== null;
    }

    /**
     * Normalized address and, when it is null, why: 'invalid_address', or
     * 'unsupported_recipient' for a valid number the channel cannot serve.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function classify(string $channel, string $raw): array
    {
        $phoneChannel = ChannelRegistry::phoneChannel($channel);
        if ($phoneChannel !== null) {
            return $phoneChannel->classifyAddress($raw);
        }
        $address = self::normalize($channel, $raw);

        return [$address, $address === null ? 'invalid_address' : null];
    }

    /** Normalized address, or null when it isn't valid for the channel. */
    public static function normalize(string $channel, string $raw): ?string
    {
        $phoneChannel = ChannelRegistry::phoneChannel($channel);
        if ($phoneChannel !== null) {
            return $phoneChannel->classifyAddress($raw)[0];
        }
        if ($channel === 'sms') {
            return SmsService::normalizeNumber($raw);
        }

        $email = strtolower(trim($raw));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false && strlen($email) <= 254 ? $email : null;
    }
}
