<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use Modules\Finance\FinanceDocumentLinkRepository;

use function App\Infrastructure\Theme\t;

/**
 * "New invoice" from a project (docs/project-finance-design.md, Stage 3-C, C1).
 *
 * Creates a DRAFT invoice through the normal InvoiceService::create() (its own
 * invoices.create check, validation, tax and totals — nothing changed there)
 * and attributes it to the project (finance_document_links, linked_type =
 * 'project'), both in ONE transaction. A draft is never revenue; it counts
 * only once issued (Stage 3-B rules, unchanged).
 *
 * The project is re-read from the server on every step (hidden form fields
 * are never trusted): viewable through RecordTypeRegistry::find() (Projects
 * available, projects.view, organization, record scope), projects.edit on it
 * (record scope) — the same rule as attaching an existing invoice (D-B4) —
 * and not completed / cancelled.
 */
final class ProjectInvoiceService
{
    public const SOURCE_TYPE = ProjectRevenueService::LINKED_TYPE;
    /** Same closed statuses as ProjectRevenueService (attach); a test pins the two together. */
    public const PROJECT_STATUSES_CLOSED = ['completed', 'cancelled'];

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly FinanceDocumentLinkRepository $links,
        private readonly ProjectRevenueService $revenue,
        private readonly AuthorizationEngine $authorization,
        private readonly TimelineService $timeline,
    ) {
    }

    /** Whether this user may start a new invoice from this project now (for the panel button). */
    public function canCreateFrom(array $actingUser, array $project): bool
    {
        return $this->authorization->can($actingUser, ProjectRevenueService::PROJECT_EDIT_PERMISSION, $project)
            && !in_array((string) ($project['status'] ?? ''), self::PROJECT_STATUSES_CLOSED, true);
    }

    /**
     * Form prefill: the project's contact, today, one line named after the
     * project. Nothing is saved; the user reviews every field.
     * Not viewable → NotFoundException; no projects.edit → AuthorizationException;
     * closed project → ValidationException.
     *
     * @return array<string, mixed>
     */
    public function draftFromProject(array $actingUser, int $projectId): array
    {
        $project = $this->eligible($actingUser, $projectId);

        return [
            'contact_id' => !empty($project['contact_id']) ? (string) $project['contact_id'] : '',
            'issue_date' => date('Y-m-d'),
            'items' => [[
                'item_name' => '',
                'description' => mb_substr($this->label($project), 0, 255),
                'quantity' => '1',
                'unit_price' => '',
                'discount_type' => 'none',
                'discount_value' => '0',
                'product_id' => null,
            ]],
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $projectId,
        ];
    }

    /**
     * The "created from project" notice for the form, re-derived on the
     * server; null when the project isn't viewable any more.
     *
     * @return array{type: string, id: int, label: string, url: ?string, no_contact: bool}|null
     */
    public function sourceNotice(array $actingUser, int $projectId): ?array
    {
        $project = $this->revenue->project($actingUser, $projectId);
        if ($project === null) {
            return null;
        }

        return [
            'type' => self::SOURCE_TYPE,
            'id' => $projectId,
            'label' => $this->label($project),
            'url' => $this->revenue->projectUrl($projectId),
            'no_contact' => empty($project['contact_id']),
        ];
    }

    /**
     * create() + the project link + timeline, atomically. The project is
     * re-checked here; an unavailable one is a form error (nothing created).
     */
    public function createFromProject(array $actingUser, array $input, int $projectId): array
    {
        try {
            $project = $this->eligible($actingUser, $projectId);
        } catch (NotFoundException) {
            throw new ValidationException(['source' => [t('finance.project.error.project_unavailable')]]);
        } catch (ValidationException $e) {
            throw new ValidationException(['source' => array_values($e->errors)[0]]);
        }
        $label = $this->label($project);

        return $this->links->atomically(function () use ($actingUser, $input, $projectId, $label): array {
            $invoice = $this->invoices->create($actingUser, $input);
            $this->links->create('invoice', (int) $invoice['id'], self::SOURCE_TYPE, $projectId, $label, (int) $actingUser['id']);
            // Project side: no invoice number or amount — project viewers may not be allowed to see the invoice.
            $this->timeline->record('project', $projectId, 'invoice_attributed', 'An invoice draft was created for this project', (int) $actingUser['id']);
            $this->timeline->record('invoice', (int) $invoice['id'], 'project_attributed', 'Created from project "' . $label . '"', (int) $actingUser['id']);

            return $invoice;
        });
    }

    /** Viewable (404) + projects.edit (403) + open (422). */
    private function eligible(array $actingUser, int $projectId): array
    {
        $project = $this->revenue->project($actingUser, $projectId);
        if ($project === null) {
            throw new NotFoundException('Project not found.');
        }
        if (!$this->authorization->can($actingUser, ProjectRevenueService::PROJECT_EDIT_PERMISSION, $project)) {
            throw new AuthorizationException();
        }
        if (in_array((string) ($project['status'] ?? ''), self::PROJECT_STATUSES_CLOSED, true)) {
            throw new ValidationException(['project' => [t('finance.project.error.closed_project')]]);
        }

        return $project;
    }

    private function label(array $project): string
    {
        $code = trim((string) ($project['code'] ?? ''));

        return (string) ($project['name'] ?? ('#' . $project['id'])) . ($code !== '' ? ' (' . $code . ')' : '');
    }
}
