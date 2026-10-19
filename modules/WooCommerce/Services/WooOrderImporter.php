<?php

declare(strict_types=1);

namespace Modules\WooCommerce\Services;

use App\Core\Events\EventBus;
use App\Infrastructure\Integrations\Events\ExternalOrderStatusChanged;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\OrganizationContext;
use Modules\WooCommerce\WooDataRepository;

/**
 * Turns one WooCommerce order / customer JSON object (from a webhook
 * payload or an API page) into CRM rows. Idempotent: the same object
 * applied twice changes nothing; an object older than what is stored
 * (external_modified_at) is skipped. Runs inside the job's
 * organization (OrganizationContext), from the cron worker only.
 *
 * A new order, or a changed status, raises the source-neutral
 * ExternalOrderStatusChanged event after the rows are written —
 * that is the hook Customer Satisfaction listens to; nothing here
 * knows about surveys.
 *
 * Contacts: a customer is linked to an existing CRM contact with the
 * same email (read-only lookup); contacts are not created here.
 */
final class WooOrderImporter
{
    public const SOURCE = 'woocommerce';

    public function __construct(
        private readonly WooDataRepository $data,
        private readonly ContactRepository $contacts,
        private readonly ?EventBus $events = null,
    ) {
    }

    /** @return bool false when the payload is not a usable order */
    public function importOrder(int $connectionId, array $order): bool
    {
        $externalId = (int) ($order['id'] ?? 0);
        $status = self::str($order['status'] ?? null, 30);
        if ($externalId <= 0 || $status === null) {
            return false;
        }
        $modified = self::gmt($order['date_modified_gmt'] ?? null);
        $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
        $email = self::email($billing['email'] ?? null);
        $phone = self::str($billing['phone'] ?? null, 40);
        $firstName = self::str($billing['first_name'] ?? null, 100);
        $lastName = self::str($billing['last_name'] ?? null, 100);
        $contactId = $email !== null ? $this->contactIdFor($email) : null;

        $customerId = null;
        $wooCustomerId = (int) ($order['customer_id'] ?? 0);
        if ($wooCustomerId > 0 || $email !== null) {
            $customerId = $this->data->upsertCustomer(
                $connectionId,
                $wooCustomerId > 0 ? 'id:' . $wooCustomerId : 'guest:' . sha1($email),
                $wooCustomerId > 0 ? $wooCustomerId : null,
                ['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName, 'phone' => $phone, 'contact_id' => $contactId],
                $modified,
            );
        }

        $completedAt = self::gmt($order['date_completed_gmt'] ?? null);
        $fields = [
            'order_number' => self::str($order['number'] ?? null, 40) ?? (string) $externalId,
            'customer_id' => $customerId,
            'status' => $status,
            'currency' => self::str($order['currency'] ?? null, 10),
            'total' => self::money($order['total'] ?? null),
            'billing_name' => self::str(trim(($firstName ?? '') . ' ' . ($lastName ?? '')), 200),
            'billing_email' => $email,
            'billing_phone' => $phone,
            'external_created_at' => self::gmt($order['date_created_gmt'] ?? null),
            'external_modified_at' => $modified,
            'external_completed_at' => $completedAt,
        ];
        $items = $this->items($connectionId, is_array($order['line_items'] ?? null) ? $order['line_items'] : []);

        $previous = $this->data->atomic(function () use ($connectionId, $externalId, $fields, $items, $modified, $status) {
            $existing = $this->data->findOrderForUpdate($connectionId, $externalId);
            if ($existing !== null && $modified !== null && $existing['external_modified_at'] !== null && $modified < $existing['external_modified_at']) {
                return ['skip' => true];
            }
            if ($existing === null) {
                $orderId = $this->data->insertOrder($connectionId, $externalId, $fields + ['status_changed_at' => date('Y-m-d H:i:s')]);
            } else {
                $orderId = (int) $existing['id'];
                if ($existing['status'] !== $status) {
                    $fields['status_changed_at'] = date('Y-m-d H:i:s');
                }
                $this->data->updateOrder($orderId, $fields);
            }
            $this->data->replaceItems($orderId, $items);

            return ['skip' => false, 'new' => $existing === null, 'status' => $existing['status'] ?? null];
        });

        if (!$previous['skip'] && ($previous['new'] || $previous['status'] !== $status)) {
            $this->events?->dispatch(new ExternalOrderStatusChanged(
                OrganizationContext::id(),
                self::SOURCE,
                $connectionId,
                self::SOURCE . ':' . $connectionId . ':' . $externalId,
                $fields['order_number'],
                $status,
                $previous['new'] ? null : $previous['status'],
                $completedAt ?? $modified ?? date('Y-m-d H:i:s'),
                (string) ($fields['billing_name'] ?? ''),
                $email,
                $phone,
                $contactId,
                $fields['total'],
                $fields['currency'],
            ));
        }

        return true;
    }

    /** A customer.created / customer.updated webhook payload. */
    public function importCustomer(int $connectionId, array $customer): bool
    {
        $externalId = (int) ($customer['id'] ?? 0);
        if ($externalId <= 0) {
            return false;
        }
        $billing = is_array($customer['billing'] ?? null) ? $customer['billing'] : [];
        $email = self::email($customer['email'] ?? null) ?? self::email($billing['email'] ?? null);
        $this->data->upsertCustomer(
            $connectionId,
            'id:' . $externalId,
            $externalId,
            [
                'email' => $email,
                'first_name' => self::str($customer['first_name'] ?? null, 100) ?? self::str($billing['first_name'] ?? null, 100),
                'last_name' => self::str($customer['last_name'] ?? null, 100) ?? self::str($billing['last_name'] ?? null, 100),
                'phone' => self::str($billing['phone'] ?? null, 40),
                'contact_id' => $email !== null ? $this->contactIdFor($email) : null,
            ],
            self::gmt($customer['date_modified_gmt'] ?? null),
        );

        return true;
    }

    private function items(int $connectionId, array $lineItems): array
    {
        $items = [];
        foreach ($lineItems as $line) {
            if (!is_array($line) || (int) ($line['id'] ?? 0) <= 0) {
                continue;
            }
            $name = self::str($line['name'] ?? null, 255) ?? '—';
            $sku = self::str($line['sku'] ?? null, 100);
            $externalProductId = (int) ($line['product_id'] ?? 0);
            $items[] = [
                'external_id' => (int) $line['id'],
                'product_id' => $externalProductId > 0 ? $this->data->upsertProduct($connectionId, $externalProductId, $name, $sku) : null,
                'external_product_id' => $externalProductId > 0 ? $externalProductId : null,
                'external_variation_id' => (int) ($line['variation_id'] ?? 0) > 0 ? (int) $line['variation_id'] : null,
                'name' => $name,
                'sku' => $sku,
                'quantity' => max(0, min(1000000, (int) ($line['quantity'] ?? 1))),
                'total' => self::money($line['total'] ?? null),
            ];
        }

        return $items;
    }

    private function contactIdFor(string $email): ?int
    {
        $contact = $this->contacts->findByEmail($email);

        return $contact !== null ? (int) $contact['id'] : null;
    }

    /** WooCommerce "*_gmt" value (Y-m-dTH:i:s, UTC) → stored UTC DATETIME, or null. */
    public static function gmt(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})/', $value, $m) !== 1) {
            return null;
        }

        return $m[1] . ' ' . $m[2];
    }

    private static function str(mixed $value, int $max): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private static function email(mixed $value): ?string
    {
        $email = is_string($value) ? strtolower(trim($value)) : '';

        return $email !== '' && strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    private static function money(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return preg_match('/^-?\d{1,20}(\.\d{1,18})?$/', $text) === 1 ? $text : '0';
    }
}
