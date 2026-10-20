<?php

declare(strict_types=1);

namespace Modules\CustomerPortal;

use App\Infrastructure\Repositories\BaseRepository;

/**
 * What a signed-in customer may read. EVERY query takes the organization
 * and the contact from the customer session and filters on BOTH in SQL —
 * a record of another contact or organization is simply not found (never
 * loaded first and checked afterwards). Ids in the URL only select among
 * the customer's own records.
 *
 * Only customer-facing columns are selected: no owner, team, source,
 * description, internal notes, created/updated-by, permissions.
 */
final class CustomerRecordsRepository extends BaseRepository
{
    /** Invoice statuses a customer sees (drafts are internal work in progress). */
    public const INVOICE_STATUSES = ['issued', 'cancelled'];
    /** Proforma statuses a customer sees. */
    public const PROFORMA_STATUSES = ['sent', 'accepted', 'rejected', 'expired', 'converted'];

    /** The contact's own profile fields (read-only in V1). */
    public function profile(int $organizationId, int $contactId): ?array
    {
        $contact = $this->findOne(
            "SELECT c.first_name, c.last_name, c.email, c.phone, c.national_id, c.economic_code,
                    c.address, c.city, c.province, c.postal_code, c.country, co.name AS company_name
             FROM contacts c
             LEFT JOIN companies co ON co.id = c.company_id AND co.organization_id = c.organization_id
             WHERE c.id = :id AND c.organization_id = :org AND c.status = 'active'",
            ['id' => $contactId, 'org' => $organizationId]
        );
        if ($contact === null) {
            return null;
        }
        $contact['emails'] = array_column($this->findMany(
            'SELECT email FROM contact_emails WHERE contact_id = :id AND organization_id = :org ORDER BY is_primary DESC, id',
            ['id' => $contactId, 'org' => $organizationId]
        ), 'email');
        $contact['phones'] = $this->findMany(
            'SELECT type, number FROM contact_phones WHERE contact_id = :id AND organization_id = :org ORDER BY is_primary DESC, id',
            ['id' => $contactId, 'org' => $organizationId]
        );

        return $contact;
    }

    // --- Invoices -------------------------------------------------------------------

    public function invoiceCount(int $organizationId, int $contactId): int
    {
        return (int) ($this->findOne(
            'SELECT COUNT(*) AS c FROM invoices i WHERE i.organization_id = :org AND i.contact_id = :contact AND i.status IN (' . self::quoted(self::INVOICE_STATUSES) . ')',
            ['org' => $organizationId, 'contact' => $contactId]
        )['c'] ?? 0);
    }

    public function invoices(int $organizationId, int $contactId, int $limit, int $offset): array
    {
        return $this->findMany(
            self::INVOICE_SELECT . ' WHERE i.organization_id = :org AND i.contact_id = :contact AND i.status IN (' . self::quoted(self::INVOICE_STATUSES) . ')
             ORDER BY i.issue_date DESC, i.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            ['org' => $organizationId, 'contact' => $contactId]
        );
    }

    /** One of the customer's invoices, with its lines — null if it is not theirs. */
    public function invoice(int $organizationId, int $contactId, int $invoiceId): ?array
    {
        $invoice = $this->findOne(
            self::INVOICE_SELECT . ' WHERE i.id = :id AND i.organization_id = :org AND i.contact_id = :contact AND i.status IN (' . self::quoted(self::INVOICE_STATUSES) . ')',
            ['id' => $invoiceId, 'org' => $organizationId, 'contact' => $contactId]
        );
        if ($invoice === null) {
            return null;
        }
        $invoice['items'] = $this->findMany(
            'SELECT ii.description, ii.quantity, ii.unit_price, ii.discount_type, ii.discount_value, ii.line_total
             FROM invoice_items ii JOIN invoices i ON i.id = ii.invoice_id
             WHERE ii.invoice_id = :id AND i.organization_id = :org AND i.contact_id = :contact ORDER BY ii.position, ii.id',
            ['id' => $invoiceId, 'org' => $organizationId, 'contact' => $contactId]
        );

        return $invoice;
    }

    // --- Proformas ------------------------------------------------------------------

    public function proformaCount(int $organizationId, int $contactId): int
    {
        return (int) ($this->findOne(
            'SELECT COUNT(*) AS c FROM proformas p WHERE p.organization_id = :org AND p.contact_id = :contact AND p.status IN (' . self::quoted(self::PROFORMA_STATUSES) . ')',
            ['org' => $organizationId, 'contact' => $contactId]
        )['c'] ?? 0);
    }

    public function proformas(int $organizationId, int $contactId, int $limit, int $offset): array
    {
        return $this->findMany(
            self::PROFORMA_SELECT . ' WHERE p.organization_id = :org AND p.contact_id = :contact AND p.status IN (' . self::quoted(self::PROFORMA_STATUSES) . ')
             ORDER BY p.issue_date DESC, p.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            ['org' => $organizationId, 'contact' => $contactId]
        );
    }

    public function proforma(int $organizationId, int $contactId, int $proformaId): ?array
    {
        $proforma = $this->findOne(
            self::PROFORMA_SELECT . ' WHERE p.id = :id AND p.organization_id = :org AND p.contact_id = :contact AND p.status IN (' . self::quoted(self::PROFORMA_STATUSES) . ')',
            ['id' => $proformaId, 'org' => $organizationId, 'contact' => $contactId]
        );
        if ($proforma === null) {
            return null;
        }
        $proforma['items'] = $this->findMany(
            'SELECT pi.description, pi.quantity, pi.unit_price, pi.discount_type, pi.discount_value, pi.line_total
             FROM proforma_items pi JOIN proformas p ON p.id = pi.proforma_id
             WHERE pi.proforma_id = :id AND p.organization_id = :org AND p.contact_id = :contact ORDER BY pi.position, pi.id',
            ['id' => $proformaId, 'org' => $organizationId, 'contact' => $contactId]
        );

        return $proforma;
    }

    // --- Surveys --------------------------------------------------------------------

    /** The customer's real (not test) survey invitations that were sent to them. */
    public function surveys(int $organizationId, int $contactId): array
    {
        return $this->findMany(
            "SELECT i.id, i.status, i.sent_at, i.created_at, s.name AS survey_name
             FROM cx_invitations i JOIN cx_surveys s ON s.id = i.survey_id AND s.organization_id = i.organization_id
             WHERE i.organization_id = :org AND i.contact_id = :contact AND i.is_test = 0 AND i.status IN ('sent', 'responded', 'expired')
             ORDER BY COALESCE(i.sent_at, i.created_at) DESC, i.id DESC",
            ['org' => $organizationId, 'contact' => $contactId]
        );
    }

    /** One of the customer's survey invitations (with its public link id) — null if it is not theirs. */
    public function surveyInvitation(int $organizationId, int $contactId, int $invitationId): ?array
    {
        return $this->findOne(
            "SELECT i.id, i.status, i.public_link_id, s.name AS survey_name
             FROM cx_invitations i JOIN cx_surveys s ON s.id = i.survey_id AND s.organization_id = i.organization_id
             WHERE i.id = :id AND i.organization_id = :org AND i.contact_id = :contact AND i.is_test = 0 AND i.status IN ('sent', 'responded', 'expired')",
            ['id' => $invitationId, 'org' => $organizationId, 'contact' => $contactId]
        );
    }

    private const INVOICE_SELECT = "SELECT i.id, i.invoice_number, i.custom_number, i.issue_date, i.due_date, i.status,
            i.subtotal, i.invoice_discount, i.tax_total, i.grand_total, i.paid_total, cur.code AS currency_code,
            (SELECT COALESCE(SUM(cn.amount), 0) FROM credit_notes cn WHERE cn.invoice_id = i.id AND cn.organization_id = i.organization_id) AS credited_total
        FROM invoices i LEFT JOIN currencies cur ON cur.id = i.currency_id";

    private const PROFORMA_SELECT = "SELECT p.id, p.custom_number, p.issue_date, p.expiry_date, p.status,
            p.subtotal, p.invoice_discount, p.tax_total, p.grand_total, cur.code AS currency_code
        FROM proformas p LEFT JOIN currencies cur ON cur.id = p.currency_id";

    /** @param string[] $values fixed, code-defined values only */
    private static function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v): string => "'" . str_replace("'", '', $v) . "'", $values));
    }
}
