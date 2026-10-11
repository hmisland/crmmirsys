<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Infrastructure\Repositories\OrganizationContext;
use App\Infrastructure\Repositories\TenantScopedRepository;

/**
 * Invoice ↔ project attribution (docs/project-finance-design.md, Stage 3-B).
 * The attribution is a finance_document_links row with linked_type =
 * 'project'; the table's UNIQUE (organization_id, document_type,
 * document_id, linked_type) key makes it ONE project per invoice, so an
 * invoice can never be counted for two projects.
 *
 * The joined reads below scope BOTH tables to the caller's organization
 * explicitly (same reasoning as the other Finance repositories with a
 * correlated subquery: tenantFindMany() would only scope the first WHERE).
 */
final class ProjectRevenueRepository extends TenantScopedRepository
{
    public const LINKED_TYPE = 'project';

    protected function table(): string
    {
        return 'finance_document_links';
    }

    /**
     * Every invoice attributed to the project (any status), with the total
     * of its own credit notes; newest first. One query — no per-invoice reads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function invoicesOfProject(int $projectId): array
    {
        return $this->findMany(
            "SELECT i.*,
                    (SELECT COALESCE(SUM(c.amount), 0) FROM credit_notes c
                      WHERE c.invoice_id = i.id AND c.organization_id = i.organization_id) AS credited
               FROM finance_document_links l
               JOIN invoices i ON i.id = l.document_id AND i.organization_id = l.organization_id
              WHERE l.organization_id = :org_id AND l.document_type = 'invoice'
                AND l.linked_type = :type AND l.linked_id = :project_id
              ORDER BY i.id DESC",
            ['org_id' => OrganizationContext::id(), 'type' => self::LINKED_TYPE, 'project_id' => $projectId]
        );
    }

    /**
     * Draft or issued invoices of this organization not attributed to any
     * project yet (the "attach" list). Order only (Stage 3-C, C3): invoices of
     * the project's own contact first, then of contacts of the project's
     * company, then the rest; newest first within each group. Eligibility is
     * unchanged; project_links is not read. The contact join is scoped to the
     * same organization.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unattributedInvoices(int $limit = 200, ?int $contactId = null, ?int $companyId = null): array
    {
        return $this->findMany(
            "SELECT i.* FROM invoices i
               LEFT JOIN contacts ct ON ct.id = i.contact_id AND ct.organization_id = i.organization_id
              WHERE i.organization_id = :org_id AND i.status IN ('draft', 'issued')
                AND NOT EXISTS (SELECT 1 FROM finance_document_links l
                                 WHERE l.organization_id = i.organization_id AND l.document_type = 'invoice'
                                   AND l.document_id = i.id AND l.linked_type = :type)
              ORDER BY CASE WHEN i.contact_id = :contact_id THEN 0 WHEN ct.company_id = :company_id THEN 1 ELSE 2 END,
                       i.id DESC
              LIMIT " . max(1, min($limit, 500)),
            ['org_id' => OrganizationContext::id(), 'type' => self::LINKED_TYPE, 'contact_id' => $contactId ?? 0, 'company_id' => $companyId ?? 0]
        );
    }

    /**
     * The project an invoice is attributed to, read with a LOCKING read so it
     * sees the latest committed row even inside a transaction whose snapshot
     * is older (used for the "already attributed?" decision, including after a
     * duplicate-key error). LOCK IN SHARE MODE works on MySQL and MariaDB.
     */
    public function attributedProjectId(int $invoiceId): ?int
    {
        $row = $this->findOne(
            "SELECT linked_id FROM finance_document_links
              WHERE organization_id = :org_id AND document_type = 'invoice' AND document_id = :doc_id AND linked_type = :type
              LOCK IN SHARE MODE",
            ['org_id' => OrganizationContext::id(), 'doc_id' => $invoiceId, 'type' => self::LINKED_TYPE]
        );

        return $row !== null ? (int) $row['linked_id'] : null;
    }

    /** @return int rows removed: 1, or 0 when it was already gone */
    public function deleteProjectLink(int $invoiceId, int $projectId): int
    {
        return $this->tenantDelete(
            "document_type = 'invoice' AND document_id = :doc_id AND linked_type = :type AND linked_id = :project_id",
            ['doc_id' => $invoiceId, 'type' => self::LINKED_TYPE, 'project_id' => $projectId]
        );
    }
}
