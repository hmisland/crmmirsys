<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Records\RecordTypeRegistry;
use Modules\Finance\FinanceDocumentLinkRepository;
use Modules\Finance\InvoiceRepository;
use Modules\Finance\ProjectRevenueRepository;

use function App\Infrastructure\Theme\t;

/**
 * Project revenue (docs/project-finance-design.md, Stage 3-B).
 *
 * Source of truth: issued invoices ATTRIBUTED to the project
 * (finance_document_links, linked_type = 'project', one project per
 * invoice). Never the contract value, installments, payments, proformas,
 * drafts, cancelled invoices or the contract ↔ project association
 * (project_links is not read here), and never through a contract.
 *
 * Per invoice currency (no conversion, no cross-currency total), over the
 * issued invoices THIS user may view (invoices.view + record scope —
 * restricted invoices are neither listed nor summed), with the same
 * per-invoice figures as the contract Billing summary (Stage 1):
 *   Gross invoiced = Σ grand_total                      (tax included)
 *   Credited       = Σ credit notes of those invoices
 *   Net revenue    = Gross − Credited                   (tax included)
 *   Paid           = Σ paid_total                       (cash; never reduces revenue)
 *   Receivable     = Σ max(0, grand − credited − paid)
 *   Refund due     = Σ max(0, paid − (grand − credited))
 * Credited, Net revenue, Receivable and Refund due need credit_notes.view;
 * without it none of them is computed or shown — only Gross and Paid, from
 * which no credit amount can be derived. (Receivable is withheld too:
 * Gross − Paid − Receivable would otherwise give the credited total. The
 * invoice page, print / PDF and the contract Billing panel are unchanged by
 * decision — see docs/project-finance-design.md, Stage 3-B.)
 *
 * Attribution (D-B4 … D-B6): invoices only; invoices.edit on the invoice
 * AND projects.edit on the project; draft or issued, never cancelled; an
 * invoice keeps the one project it has (no move). Detach only while the
 * invoice is a draft. Both run in one transaction whose first statement
 * locks the invoice row — the same lock its issue / payments / credit notes
 * / cancel use — so status and attribution can't race.
 */
final class ProjectRevenueService
{
    public const LINKED_TYPE = ProjectRevenueRepository::LINKED_TYPE;
    /** Projects' key, referenced by name (Finance already names other modules' keys this way). */
    public const PROJECT_EDIT_PERMISSION = 'projects.edit';
    private const PROJECT_STATUSES_CLOSED = ['completed', 'cancelled'];

    public function __construct(
        private readonly ProjectRevenueRepository $revenue,
        private readonly FinanceDocumentLinkRepository $links,
        private readonly InvoiceRepository $invoices,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
        private readonly ?RecordTypeRegistry $recordTypes = null,
    ) {
    }

    /** The project row if this user may view it (Projects available, projects.view, organization, record scope), else null. */
    public function project(array $actingUser, int $projectId): ?array
    {
        if ($projectId <= 0 || $this->recordTypes === null) {
            return null;
        }

        return $this->recordTypes->find(self::LINKED_TYPE, $projectId, $actingUser);
    }

    public function projectUrl(int $projectId): ?string
    {
        return $this->recordTypes?->get(self::LINKED_TYPE)?->showUrl($projectId);
    }

    /**
     * The project panel data for this user (caller checked invoices.view).
     *
     * @param \Closure(string): bool $can blanket permission check for this user
     * @return array{summary: array<int, array>, documents: array, show_credits: bool, can_attach: bool, candidates: array, detachable: array<int, bool>}
     */
    public function panelFor(array $actingUser, array $project, \Closure $can): array
    {
        $projectId = (int) $project['id'];
        $showCredits = $can(Permission::CREDIT_NOTES_VIEW);
        $visible = array_values(array_filter(
            $this->revenue->invoicesOfProject($projectId),
            fn (array $i) => $this->authorization->can($actingUser, Permission::INVOICES_VIEW, $i)
        ));

        $summary = [];
        foreach ($visible as $i) {
            if ($i['status'] !== 'issued') {
                continue; // drafts are listed, never counted; cancelled ones are excluded entirely
            }
            $cur = (int) $i['currency_id'];
            $s = $summary[$cur] ?? [
                'currency_id' => $cur, 'gross' => '0', 'paid' => '0',
                'receivable' => $showCredits ? '0' : null,
                'credited' => $showCredits ? '0' : null, 'net' => $showCredits ? '0' : null, 'refund_due' => $showCredits ? '0' : null,
            ];
            $grand = (string) $i['grand_total'];
            $credited = (string) $i['credited'];
            $paid = (string) $i['paid_total'];
            $effective = bcsub($grand, $credited, 18);
            $owed = bcsub($effective, $paid, 18);

            $s['gross'] = bcadd($s['gross'], $grand, 18);
            $s['paid'] = bcadd($s['paid'], $paid, 18);
            if ($showCredits) {
                $s['receivable'] = bcadd($s['receivable'], bccomp($owed, '0', 18) > 0 ? $owed : '0', 18);
                $s['credited'] = bcadd($s['credited'], $credited, 18);
                $s['net'] = bcadd($s['net'], $effective, 18);
                $s['refund_due'] = bcadd($s['refund_due'], bccomp($owed, '0', 18) < 0 ? bcmul($owed, '-1', 18) : '0', 18);
            }
            $summary[$cur] = $s;
        }

        $canManage = $this->canManageProject($actingUser, $project) && $can(Permission::INVOICES_EDIT);
        $editable = fn (array $i): bool => $this->authorization->can($actingUser, Permission::INVOICES_VIEW, $i)
            && $this->authorization->can($actingUser, Permission::INVOICES_EDIT, $i);

        $detachable = [];
        foreach ($visible as $i) {
            $detachable[(int) $i['id']] = $canManage && $i['status'] === 'draft' && $editable($i);
        }

        return [
            'summary' => array_values($summary),
            'documents' => array_map(fn (array $i) => [
                'id' => (int) $i['id'],
                'number' => ($i['custom_number'] ?? '') !== '' ? (string) $i['custom_number'] : (!empty($i['invoice_number']) ? (string) $i['invoice_number'] : '#' . $i['id']),
                'issue_date' => (string) $i['issue_date'],
                'status' => (string) $i['status'],
                'amount' => (string) $i['grand_total'],
                'currency_id' => (int) $i['currency_id'],
            ], $visible),
            'show_credits' => $showCredits,
            'can_attach' => $canManage && !in_array((string) $project['status'], self::PROJECT_STATUSES_CLOSED, true),
            // Stage 3-C (C3): the project's contact / company first — order only.
            'candidates' => $canManage ? array_values(array_filter($this->revenue->unattributedInvoices(
                200,
                !empty($project['contact_id']) ? (int) $project['contact_id'] : null,
                !empty($project['company_id']) ? (int) $project['company_id'] : null,
            ), $editable)) : [],
            'detachable' => $detachable,
        ];
    }

    /**
     * Attributes an invoice to the project. Idempotent for the same project;
     * an invoice already attributed to ANOTHER project is refused (no move).
     */
    public function attach(array $actingUser, int $projectId, int $invoiceId): void
    {
        [$project, $invoice] = $this->authorized($actingUser, $projectId, $invoiceId);
        if (in_array((string) $project['status'], self::PROJECT_STATUSES_CLOSED, true)) {
            throw new ValidationException(['project' => [t('finance.project.error.closed_project')]]);
        }
        $label = $this->projectLabel($project);

        $this->links->atomically(function () use ($actingUser, $projectId, $invoiceId, $invoice, $label): void {
            $locked = $this->invoices->lockForUpdate($invoiceId);
            if ($locked === null) {
                throw new NotFoundException('Invoice not found.');
            }
            if ($locked['status'] === 'cancelled') {
                throw new ValidationException(['invoice' => [t('finance.project.error.invoice_cancelled')]]);
            }
            if ($this->alreadyAttributed($invoiceId, $projectId)) {
                return;
            }

            try {
                $this->links->create('invoice', $invoiceId, self::LINKED_TYPE, $projectId, $label, (int) $actingUser['id']);
            } catch (\PDOException $e) {
                // A concurrent request attributed it first (UNIQUE key): same project → done, other → refused.
                if (($e->errorInfo[1] ?? null) === 1062 && $this->alreadyAttributed($invoiceId, $projectId)) {
                    return;
                }
                throw $e;
            }

            // Project side: no invoice number or amount — project viewers may not be allowed to see the invoice.
            $this->timeline->record('project', $projectId, 'invoice_attributed', 'An invoice was attributed to this project', (int) $actingUser['id']);
            $this->timeline->record('invoice', $invoiceId, 'project_attributed', 'Attributed to project "' . $label . '"', (int) $actingUser['id']);
        });
    }

    /** Removes the attribution — only while the invoice is still a draft (checked on the locked row). */
    public function detach(array $actingUser, int $projectId, int $invoiceId): void
    {
        [$project] = $this->authorized($actingUser, $projectId, $invoiceId);
        $link = $this->links->forDocument('invoice', $invoiceId, self::LINKED_TYPE);
        if ($link === null || (int) $link['linked_id'] !== $projectId) {
            throw new NotFoundException('Attribution not found.');
        }
        $label = $this->projectLabel($project);

        $this->links->atomically(function () use ($actingUser, $projectId, $invoiceId, $label): void {
            $locked = $this->invoices->lockForUpdate($invoiceId);
            if ($locked === null) {
                throw new NotFoundException('Invoice not found.');
            }
            if ($locked['status'] !== 'draft') {
                throw new ValidationException(['invoice' => [t('finance.project.error.frozen')]]);
            }
            if ($this->revenue->deleteProjectLink($invoiceId, $projectId) !== 1) {
                return; // already removed by a concurrent request: nothing happened here
            }
            $this->timeline->record('project', $projectId, 'invoice_detached', 'An invoice was detached from this project', (int) $actingUser['id']);
            $this->timeline->record('invoice', $invoiceId, 'project_detached', 'Detached from project "' . $label . '"', (int) $actingUser['id']);
        });
    }

    // ---- Internals ---------------------------------------------------------

    /**
     * Project viewable (404) + projects.edit on it (403); invoice of this
     * organization (404) + invoices.view and invoices.edit on it (403).
     *
     * @return array{0: array, 1: array}
     */
    private function authorized(array $actingUser, int $projectId, int $invoiceId): array
    {
        $project = $this->project($actingUser, $projectId);
        if ($project === null) {
            throw new NotFoundException('Project not found.');
        }
        if (!$this->canManageProject($actingUser, $project)) {
            throw new AuthorizationException();
        }
        $invoice = $invoiceId > 0 ? $this->invoices->findById($invoiceId) : null;
        if ($invoice === null) {
            throw new NotFoundException('Invoice not found.');
        }
        if (!$this->authorization->can($actingUser, Permission::INVOICES_VIEW, $invoice)
            || !$this->authorization->can($actingUser, Permission::INVOICES_EDIT, $invoice)) {
            throw new AuthorizationException();
        }

        return [$project, $invoice];
    }

    private function canManageProject(array $actingUser, array $project): bool
    {
        return $this->authorization->can($actingUser, self::PROJECT_EDIT_PERMISSION, $project);
    }

    /** true when attributed to $projectId; ValidationException when attributed to another project; false when free. */
    private function alreadyAttributed(int $invoiceId, int $projectId): bool
    {
        $existing = $this->revenue->attributedProjectId($invoiceId); // locking read: latest committed row
        if ($existing === null) {
            return false;
        }
        if ($existing !== $projectId) {
            throw new ValidationException(['invoice' => [t('finance.project.error.already_attributed')]]);
        }

        return true;
    }

    private function projectLabel(array $project): string
    {
        $code = trim((string) ($project['code'] ?? ''));

        return (string) ($project['name'] ?? ('#' . $project['id'])) . ($code !== '' ? ' (' . $code . ')' : '');
    }
}
