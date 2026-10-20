<?php

declare(strict_types=1);

namespace Modules\WooCommerce\ContactSync;

use App\Application\Services\TimelineService;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Modules\OrganizationEntitlements;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Repositories\ContactEmailRepository;
use App\Infrastructure\Repositories\ContactPhoneRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Repositories\TagRepository;
use Modules\WooCommerce\WooCommerceModule;

/**
 * WooCommerce customer → CRM contact, per shop (docs/integrations-architecture.md, Phase A).
 *
 * For each pending woo_customers row of a shop whose sync is on:
 *  1. linked to a contact of this organization by an earlier sync run →
 *     that contact; otherwise look up existing contacts by normalized email and by
 *     Iranian mobile (main and additional emails / numbers);
 *  2. several contacts for one identifier, or email and mobile pointing
 *     at different contacts → "conflict": nothing is merged or changed;
 *  3. one contact → link it and fill ONLY its empty fields with valid
 *     values (never overwrite, never erase); its source is kept; the
 *     shop's contact group is set only when it has none; the shop's tags
 *     are added (never duplicated);
 *  4. none → create a contact only with a first name AND a reliable
 *     identifier (valid email or Iranian mobile); otherwise "skipped".
 *     New contacts get the shop's group, tags and source, and belong to
 *     the user who switched the sync on.
 *
 * Idempotent: a customer is claimed before processing and is pending
 * again only when its data changes; running twice changes nothing.
 * Nothing here sends a message or starts a campaign.
 */
final class CustomerContactSync
{
    public const STATUSES = ['created', 'matched', 'updated', 'skipped', 'conflict'];

    /** @var array<int, string> */
    private array $locales = [];

    public function __construct(
        private readonly ContactSyncRepository $sync,
        private readonly ContactRepository $contacts,
        private readonly ContactPhoneRepository $phones,
        private readonly ContactEmailRepository $emails,
        private readonly ContactCategoryRepository $categories,
        private readonly TagRepository $tags,
        private readonly TimelineService $timeline,
        private readonly OrganizationEntitlements $entitlements,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Right after an import wrote a customer (cron worker, inside the
     * organization): syncs it when the shop's sync is on.
     *
     * @return int|null the linked contact id, when there is one
     */
    public function syncCustomerNow(int $connectionId, int $customerId): ?int
    {
        $settings = $this->usableSettings($connectionId);
        if ($settings !== null && $this->sync->claim($customerId)) {
            $this->processClaimed($settings, $customerId);
        }
        $customer = $this->sync->customer($customerId);

        return $customer !== null && $customer['contact_id'] !== null ? (int) $customer['contact_id'] : null;
    }

    /**
     * Pending customers of one shop of the CURRENT organization (manual
     * "Sync contacts now", and the cron task). Records the run summary.
     *
     * @return array{created: int, matched: int, updated: int, skipped: int, conflict: int, remaining: int}|null null = sync off / not usable
     */
    public function processPending(int $connectionId, int $limit, float $deadline): ?array
    {
        $settings = $this->usableSettings($connectionId);
        if ($settings === null) {
            return null;
        }
        $this->sync->releaseStale($connectionId);
        $summary = array_fill_keys(self::STATUSES, 0);
        foreach ($this->sync->pendingIds($connectionId, $limit) as $customerId) {
            if (microtime(true) >= $deadline) {
                break;
            }
            if (!$this->sync->claim($customerId)) {
                continue;
            }
            $summary[$this->processClaimed($settings, $customerId)]++;
        }
        $summary['remaining'] = count($this->sync->pendingIds($connectionId, 1000));

        if (array_sum(array_intersect_key($summary, array_flip(self::STATUSES))) > 0 || $settings['last_run_at'] === null) {
            $this->sync->saveSettings($connectionId, [
                'last_run_at' => date('Y-m-d H:i:s'),
                'last_run_summary' => (string) json_encode($summary),
                'last_error' => null,
            ]);
        }

        return $summary;
    }

    /** Cron task "woocommerce.contacts": every enabled shop, within the time budget. */
    public function processAllEnabled(int $budgetSeconds, int $perShop = 200): array
    {
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $this->entitlements->reset();
        $totals = array_fill_keys(self::STATUSES, 0);
        foreach ($this->sync->enabledConnections() as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $organizationId = (int) $row['organization_id'];
            if (!$this->entitlements->organizationHas($organizationId, WooCommerceModule::KEY)) {
                continue;
            }
            try {
                $summary = OrganizationContext::runAs($organizationId, fn () => $this->processPending((int) $row['connection_id'], $perShop, $deadline));
                foreach (self::STATUSES as $status) {
                    $totals[$status] += (int) ($summary[$status] ?? 0);
                }
            } catch (\Throwable $e) {
                $this->logger?->error('WooCommerce contact sync: connection ' . (int) $row['connection_id'] . ' failed: ' . get_class($e));
            }
        }

        return $totals;
    }

    /** The shop's settings when the sync may run now (on, contact group set, module held). */
    private function usableSettings(int $connectionId): ?array
    {
        $settings = $this->sync->settings($connectionId);
        if ($settings === null || (int) $settings['enabled'] !== 1) {
            return null;
        }
        if (!$this->entitlements->organizationHas(OrganizationContext::id(), WooCommerceModule::KEY)) {
            return null;
        }
        if ($settings['category_id'] === null || $this->categories->findById((int) $settings['category_id']) === null) {
            // The group was deleted after activation: stop until someone picks a new one.
            if ($settings['last_error'] !== 'woocommerce.contacts.error.category_missing') {
                $this->sync->saveSettings($connectionId, ['last_error' => 'woocommerce.contacts.error.category_missing']);
            }

            return null;
        }

        return $settings;
    }

    /** @return string the outcome status (one of STATUSES) */
    private function processClaimed(array $settings, int $customerId): string
    {
        try {
            [$status, $note, $contactId] = $this->sync->atomically(fn () => $this->resolve($settings, $customerId));
        } catch (\Throwable $e) {
            $this->logger?->error('WooCommerce contact sync: customer ' . $customerId . ' failed: ' . get_class($e));
            [$status, $note, $contactId] = ['skipped', 'woocommerce.contacts.note.error', null];
        }
        $this->sync->finish($customerId, $status, $note, $contactId);

        return $status;
    }

    /** @return array{0: string, 1: ?string, 2: ?int} [status, note key, contact id] */
    private function resolve(array $settings, int $customerId): array
    {
        $customer = $this->sync->customer($customerId);
        if ($customer === null) {
            return ['skipped', 'woocommerce.contacts.note.error', null];
        }
        $values = ContactFieldMapper::map($customer, (array) json_decode((string) ($settings['mapping_json'] ?? ''), true), $this->locale());

        // A link made by an earlier sync run is kept (stable, idempotent). The email-only link the
        // importer sets on its own (Phase 1) is just a hint: it must pass the same checks as a new match.
        $contact = $customer['contact_id'] !== null && $customer['contact_synced_at'] !== null ? $this->contacts->findById((int) $customer['contact_id']) : null;
        if ($contact === null) {
            $byEmail = $values['email'] !== null ? $this->sync->contactIdsByEmail($values['email']) : [];
            $byMobile = $values['mobile'] !== null ? $this->sync->contactIdsByPhone(ContactFieldMapper::mobileVariants($values['mobile'])) : [];
            if (count($byEmail) > 1 || count($byMobile) > 1) {
                return ['conflict', 'woocommerce.contacts.note.ambiguous', null];
            }
            if ($byEmail !== [] && $byMobile !== [] && $byEmail[0] !== $byMobile[0]) {
                return ['conflict', 'woocommerce.contacts.note.identifiers_disagree', null];
            }
            $matchId = $byEmail[0] ?? $byMobile[0] ?? null;
            $contact = $matchId !== null ? $this->contacts->findById($matchId) : null;
        }

        if ($contact === null) {
            return $this->create($settings, $values);
        }

        $changed = $this->fillEmpty($settings, $contact, $values);
        $this->attachTags($settings, (int) $contact['id']);

        return [$changed ? 'updated' : 'matched', null, (int) $contact['id']];
    }

    /** @return array{0: string, 1: ?string, 2: ?int} */
    private function create(array $settings, array $values): array
    {
        if ($values['first_name'] === null) {
            return ['skipped', 'woocommerce.contacts.note.missing_name', null];
        }
        if ($values['email'] === null && $values['mobile'] === null) {
            return ['skipped', 'woocommerce.contacts.note.no_identifier', null];
        }

        $fields = array_filter([
            'first_name' => $values['first_name'],
            'last_name' => $values['last_name'],
            'email' => $values['email'],
            // contacts.phone mirrors the primary number, as on the contact form (mobile first).
            'phone' => $values['mobile'] ?? $values['landline'],
            'address' => $values['address'],
            'city' => $values['city'],
            'province' => $values['province'],
            'postal_code' => $values['postal_code'],
            'country' => $values['country'],
            'category_id' => (int) $settings['category_id'],
            'source' => $settings['source'] !== null && $settings['source'] !== '' ? mb_substr((string) $settings['source'], 0, 100) : null,
            'owner_id' => $settings['owner_user_id'] !== null ? (int) $settings['owner_user_id'] : null,
            'status' => 'active',
        ], fn ($v) => $v !== null);
        $id = $this->contacts->create($fields);

        if ($values['mobile'] !== null) {
            $this->phones->add($id, 'mobile', $values['mobile'], true);
        }
        if ($values['landline'] !== null) {
            $this->phones->add($id, 'landline', $values['landline'], $values['mobile'] === null);
        }
        if ($values['email'] !== null) {
            $this->emails->add($id, $values['email'], true);
        }
        $this->attachTags($settings, $id);
        $this->timeline->record('contact', $id, 'created', 'Contact created from WooCommerce store sync', null);

        return ['created', null, $id];
    }

    /** Fills only the contact's empty fields; returns whether anything changed. */
    private function fillEmpty(array $settings, array $contact, array $values): bool
    {
        $id = (int) $contact['id'];
        $fields = [];
        foreach (['last_name', 'address', 'city', 'province', 'postal_code', 'country'] as $column) {
            if ($values[$column] !== null && trim((string) ($contact[$column] ?? '')) === '') {
                $fields[$column] = $values[$column];
            }
        }
        if ($contact['category_id'] === null) {
            $fields['category_id'] = (int) $settings['category_id'];
        }

        $channels = $this->sync->contactChannels($id);
        $hasPhone = trim((string) ($contact['phone'] ?? '')) !== '';
        $added = false;
        if ($values['email'] !== null && trim((string) ($contact['email'] ?? '')) === '' && $channels['emails'] === []) {
            $fields['email'] = $values['email'];
            $this->emails->add($id, $values['email'], true);
            $added = true;
        }
        // Mobile slot = contacts.phone + mobile numbers; filled only when the contact has no number at all.
        if ($values['mobile'] !== null && !$channels['mobile'] && !$hasPhone) {
            $this->phones->add($id, 'mobile', $values['mobile'], true);
            $fields['phone'] = $values['mobile'];
            $hasPhone = true;
            $added = true;
        }
        // Landline slot: filled when the contact has no landline; primary (and contacts.phone) only without any number.
        if ($values['landline'] !== null && !$channels['landline'] && $values['landline'] !== trim((string) ($contact['phone'] ?? ''))) {
            $this->phones->add($id, 'landline', $values['landline'], !$hasPhone);
            if (!$hasPhone) {
                $fields['phone'] = $values['landline'];
            }
            $added = true;
        }

        $this->contacts->updateContact($id, $fields);
        if ($fields !== [] || $added) {
            $this->timeline->record('contact', $id, 'updated', 'Empty contact fields completed from WooCommerce store sync', null);

            return true;
        }

        return false;
    }

    private function attachTags(array $settings, int $contactId): void
    {
        foreach (self::tagIds($settings) as $tagId) {
            // Only tags of this organization (tag ids are stored per shop, checked again here).
            if ($this->tags->findById($tagId) !== null) {
                $this->tags->attach($tagId, 'contact', $contactId);
            }
        }
    }

    /** @return int[] */
    public static function tagIds(?array $settings): array
    {
        $ids = json_decode((string) ($settings['tag_ids'] ?? ''), true);

        return is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids)))) : [];
    }

    private function locale(): string
    {
        $organizationId = OrganizationContext::id();

        return $this->locales[$organizationId] ??= (string) ((new OrganizationRepository())->findById($organizationId)['default_language'] ?? 'fa');
    }
}
