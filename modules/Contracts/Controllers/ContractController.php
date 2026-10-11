<?php

declare(strict_types=1);

namespace Modules\Contracts\Controllers;

use App\Application\Controllers\BaseController;
use App\Application\Services\AttachmentService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Localization\Locale;
use App\Infrastructure\Localization\LocaleFormatter;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Repositories\CurrencyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;
use Modules\Contracts\Services\ContractService;

use function App\Infrastructure\Theme\t;

/**
 * Contract pages (step 2.5). Thin: every rule — permission, record
 * scope, status, validation — is ContractService's. Routes are wrapped
 * in ModuleMiddleware('contracts') + RbacMiddleware (ContractsModule).
 * Service errors are translation keys; translated here.
 */
final class ContractController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly ContractService $contracts,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly AttachmentService $attachments,
        private readonly ContactRepository $contactRepository,
        private readonly CompanyRepository $companyRepository,
        private readonly CurrencyRepository $currencies,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $status = (string) $request->query('status', '');
        $filters = [
            'search' => trim((string) $request->query('q', '')),
            'effective_status' => in_array($status, ContractService::STATUSES, true) ? $status : '',
            'category_id' => (int) $request->query('category', 0) ?: null,
            'owner_id' => (string) $request->query('mine', '') === '1' ? (int) $user['id'] : null,
            'expiring_within_days' => (string) $request->query('expiring', '') === '1' ? 30 : null,
            // From the contact/company panels' "view all" link.
            'contact_id' => (int) $request->query('contact', 0) ?: null,
            'company_id' => (int) $request->query('company', 0) ?: null,
        ];

        $pagination = Pagination::fromRequest($request, $this->contracts->count($user, $filters));
        $rows = $this->contracts->list($user, $filters, $pagination->perPage, $pagination->offset());

        $rowActions = [];
        foreach ($rows as $row) {
            $allowed = $this->contracts->allowedActions($user, $row);
            $rowActions[(int) $row['id']] = ['edit' => $allowed['edit'], 'delete' => $allowed['delete'], 'reason' => t('contracts.locked_reason')];
        }

        return $this->viewWithLayout('contracts/index', 'layout', [
            'contracts' => $rows,
            'rowActions' => $rowActions,
            'pagination' => $pagination,
            'categories' => $this->contracts->categories($user),
            'q' => (string) $request->query('q', ''),
            'status' => $filters['effective_status'],
            'category' => (int) ($filters['category_id'] ?? 0),
            'mine' => $filters['owner_id'] !== null,
            'expiring' => $filters['expiring_within_days'] !== null,
            'partyFilter' => array_filter(['contact' => $filters['contact_id'], 'company' => $filters['company_id']]),
            'canCreate' => $this->can('contracts.create'),
            'canManageSettings' => $this->can('contracts.manage_settings'),
            'fmt' => $this->formatter(),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        // "Create contract" on a deal page (step 2.8): prefill from the deal,
        // read through RecordTypeRegistry. A deal the user can't see → 404.
        $fromDeal = (int) $request->query('from_deal', 0);
        if ($fromDeal > 0) {
            $draft = $this->contracts->draftFromDeal(CurrentUser::user(), $fromDeal);
            if ($draft === null) {
                throw new NotFoundException();
            }

            return $this->form(null, [], $draft);
        }

        // Optional prefill, e.g. from a contact or company page (?contact_id=…).
        $old = array_filter([
            'contact_id' => (string) $request->query('contact_id', ''),
            'company_id' => (string) $request->query('company_id', ''),
        ], fn ($v) => $v !== '');

        return $this->form(null, [], $old);
    }

    public function create(Request $request): Response
    {
        $input = $request->all();
        $sourceType = (string) ($input['source_type'] ?? '');
        $sourceId = (int) ($input['source_id'] ?? 0);

        try {
            $contract = $sourceType !== '' && $sourceId > 0
                ? $this->contracts->createLinked(CurrentUser::user(), $input, $sourceType, $sourceId)
                : $this->contracts->create(CurrentUser::user(), $input);
        } catch (ValidationException $e) {
            return $this->form(null, $this->translated($e->errors), $input, 422);
        }

        Session::setFlash('success', t('contracts.flash.created'));

        return $this->redirect('/contracts/' . $contract['id']);
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');
        $contract = $this->contracts->find($user, $id);

        $parent = null;
        if (!empty($contract['parent_contract_id'])) {
            try {
                $parent = $this->contracts->find($user, (int) $contract['parent_contract_id']);
            } catch (NotFoundException | AuthorizationException) {
                $parent = null; // gone, or outside this user's scope
            }
        }

        $currency = $contract['currency_id'] !== null ? $this->currencies->findById((int) $contract['currency_id']) : null;

        return $this->viewWithLayout('contracts/show', 'layout', [
            'contract' => $contract,
            'actions' => $this->contracts->allowedActions($user, $contract),
            'category' => $contract['category_id'] !== null ? $this->findCategoryName($user, (int) $contract['category_id']) : null,
            'contact' => $contract['contact_id'] !== null ? $this->contactRepository->findById((int) $contract['contact_id']) : null,
            'company' => $contract['company_id'] !== null ? $this->companyRepository->findById((int) $contract['company_id']) : null,
            'currencyCode' => $currency['code'] ?? null,
            'parent' => $parent,
            'renewals' => $this->contracts->list($user, ['parent_contract_id' => $id]),
            'links' => $this->contracts->links($user, $id),
            'notes' => $this->notes->forSubject('contract', $id),
            'tags' => $this->tags->forSubject('contract', $id),
            'allTags' => $this->tags->allForOrganization(),
            'timeline' => $this->timeline->forSubject('contract', $id),
            'attachments' => $this->attachments->forSubject('contract', $id),
            'fmt' => $this->formatter(),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $user = CurrentUser::user();
        $contract = $this->contracts->find($user, (int) $request->attribute('id'));

        // Never show a form the user can't save: same rule update() enforces
        // (edit permission on this record + an editable status).
        if (!$this->contracts->allowedActions($user, $contract)['edit']) {
            throw new AuthorizationException();
        }

        return $this->form($contract, [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->contracts->update(CurrentUser::user(), $id, $request->all());
        } catch (ValidationException $e) {
            return $this->form($this->contracts->find(CurrentUser::user(), $id), $this->translated($e->errors), $request->all(), 422);
        }

        Session::setFlash('success', t('contracts.flash.updated'));

        return $this->redirect('/contracts/' . $id);
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->contracts->delete(CurrentUser::user(), $id);
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t('contracts.flash.deleted'));

        return $this->redirect('/contracts');
    }

    public function submit(Request $request): Response
    {
        return $this->lifecycle($request, fn (array $u, int $id) => $this->contracts->submit($u, $id), 'contracts.flash.submitted');
    }

    public function returnToDraft(Request $request): Response
    {
        return $this->lifecycle($request, fn (array $u, int $id) => $this->contracts->returnToDraft($u, $id), 'contracts.flash.returned_to_draft');
    }

    public function approve(Request $request): Response
    {
        $signedOn = (string) $request->input('signed_on', '');

        return $this->lifecycle($request, fn (array $u, int $id) => $this->contracts->approve($u, $id, $signedOn), 'contracts.flash.activated');
    }

    public function activate(Request $request): Response
    {
        $signedOn = (string) $request->input('signed_on', '');

        return $this->lifecycle($request, fn (array $u, int $id) => $this->contracts->activate($u, $id, $signedOn), 'contracts.flash.activated');
    }

    public function cancel(Request $request): Response
    {
        return $this->lifecycle($request, fn (array $u, int $id) => $this->contracts->cancel($u, $id), 'contracts.flash.cancelled');
    }

    public function terminate(Request $request): Response
    {
        $reason = (string) $request->input('reason', '');

        return $this->lifecycle($request, fn (array $u, int $id) => $this->contracts->terminate($u, $id, $reason), 'contracts.flash.terminated');
    }

    public function renew(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $renewal = $this->contracts->renew(CurrentUser::user(), $id);
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t('contracts.flash.renewed'));

        return $this->redirect('/contracts/' . $renewal['id']);
    }

    public function unlink(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $this->contracts->unlink(CurrentUser::user(), $id, (int) $request->attribute('linkId'));
        Session::setFlash('success', t('contracts.flash.unlinked'));

        return $this->redirect('/contracts/' . $id);
    }

    // ---- helpers -----------------------------------------------------------

    private function lifecycle(Request $request, callable $action, string $flashKey): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $action(CurrentUser::user(), $id);
        } catch (ValidationException $e) {
            return $this->backWithError($id, $e);
        }

        Session::setFlash('success', t($flashKey));

        return $this->redirect('/contracts/' . $id);
    }

    private function backWithError(int $id, ValidationException $e): Response
    {
        $first = array_values($e->errors)[0][0] ?? 'contracts.error.invalid_transition';
        Session::setFlash('error', t($first));

        return $this->redirect('/contracts/' . $id);
    }

    private function form(?array $contract, array $errors, array $old, int $status = 200): Response
    {
        $user = CurrentUser::user();

        // The "created from" notice is re-derived on the server, never taken from the form.
        $source = null;
        if ($contract === null && ($old['source_type'] ?? '') === 'deal' && (int) ($old['source_id'] ?? 0) > 0) {
            $draft = $this->contracts->draftFromDeal($user, (int) $old['source_id']);
            $source = $draft === null ? null : ['type' => 'deal', 'id' => (int) $old['source_id'], 'label' => $draft['source_label'], 'url' => $draft['source_url']];
        }
        unset($old['source_label'], $old['source_url']);

        return $this->viewWithLayout('contracts/form', 'layout', [
            'source' => $source,
            'contract' => $contract,
            'errors' => $errors,
            'old' => $old,
            'categories' => $this->contracts->categories($user),
            'contacts' => $this->contactRepository->search([]),
            'companies' => $this->companyRepository->search([]),
            'currencies' => $this->currencies->all(),
        ], $errors === [] ? $status : 422);
    }

    /** @param array<string, string[]> $errors translation keys */
    private function translated(array $errors): array
    {
        return array_map(fn (array $messages) => array_map(fn (string $key) => t($key), $messages), $errors);
    }

    private function findCategoryName(array $user, int $categoryId): ?string
    {
        foreach ($this->contracts->categories($user) as $category) {
            if ((int) $category['id'] === $categoryId) {
                return $category['name'];
            }
        }

        return null;
    }

    /** Jalali dates for fa, locale digits/grouping — same as the Appointments pages. */
    private function formatter(): LocaleFormatter
    {
        $translator = Locale::get();

        return new LocaleFormatter($translator !== null ? $translator->locale() : 'en');
    }

    private function can(string $permission): bool
    {
        return $this->contracts->userCan(CurrentUser::user(), $permission);
    }
}
