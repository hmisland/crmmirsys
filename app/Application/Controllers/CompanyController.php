<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\AttachmentService;
use App\Application\Services\CompanyCategoryService;
use App\Application\Services\CompanyService;
use App\Application\Services\ContactService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

final class CompanyController extends BaseController
{
    public function __construct(
        TemplateEngine $templates,
        private readonly CompanyService $companies,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly CustomFieldService $customFields,
        private readonly ContactRepository $contacts,
        private readonly AttachmentService $attachments,
        private readonly CompanyCategoryService $categories,
        private readonly ContactService $contactService,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $categoryId = (int) $request->query('category_id', 0);
        $filters = [
            'search' => trim((string) $request->query('q', '')),
            'owner_id' => (string) $request->query('mine', '') === '1' ? (int) CurrentUser::id() : null,
            'category_id' => $categoryId > 0 ? $categoryId : null,
        ];
        $pagination = Pagination::fromRequest($request, $this->companies->count(CurrentUser::user(), $filters));
        $companies = $this->companies->listPage(CurrentUser::user(), $filters, $pagination->perPage, $pagination->offset());

        return $this->viewWithLayout('crm/companies/index', 'layout', [
            'companies' => $companies,
            'rowActions' => $this->companies->rowPermissions(CurrentUser::user(), $companies),
            'pagination' => $pagination,
            'mine' => $filters['owner_id'] !== null,
            'q' => (string) $request->query('q', ''),
            'categories' => $this->categories->all(),
            'categoryId' => $categoryId,
            'canManageCategories' => $this->categories->canManage(CurrentUser::user()),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->form(null, [], []);
    }

    /**
     * Creates the company, then (in the same request) its tags, attached
     * files, custom field values and linked contacts. A failing file is
     * reported but does not undo the company.
     */
    public function create(Request $request): Response
    {
        $user = CurrentUser::user();

        try {
            $company = $this->companies->create($user, $request->all());
        } catch (ValidationException $e) {
            return $this->form(null, $e->errors, $request->all(), 422);
        }

        $id = (int) $company['id'];
        $this->customFields->saveValues('company', $id, (array) $request->input('custom', []));

        foreach ((array) $request->input('tags', []) as $tag) {
            if (trim((string) $tag) !== '') {
                $this->tags->attach('company', $id, (string) $tag, (int) $user['id']);
            }
        }

        $failedFiles = [];
        foreach ($request->files('attachments') as $file) {
            try {
                $this->attachments->upload('company', $id, (int) $user['organization_id'], (int) $user['id'], $file);
            } catch (ValidationException $e) {
                $failedFiles[] = (string) ($file['name'] ?? '');
            }
        }

        $this->companies->assignContacts($user, $id, (array) $request->input('contact_ids', []));

        Session::setFlash('success', t('companies.flash.created'));
        if ($failedFiles !== []) {
            Session::setFlash('error', t('companies.error.files_failed', ['files' => implode('، ', $failedFiles)]));
        }

        return $this->redirect('/companies/' . $id);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $company = $this->companies->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('crm/companies/show', 'layout', [
            'company' => $company,
            'category' => $company['category_id'] !== null ? $this->categories->find((int) $company['category_id']) : null,
            'contacts' => $this->contacts->search(['company_id' => $id]),
            'notes' => $this->notes->forSubject('company', $id),
            'tags' => $this->tags->forSubject('company', $id),
            'allTags' => $this->tags->allForOrganization(),
            'timeline' => $this->timeline->forSubject('company', $id),
            'customFieldDefs' => $this->customFields->definitionsFor('company'),
            'customFieldValues' => $this->customFields->valuesFor('company', $id),
            'attachments' => $this->attachments->forSubject('company', $id),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        return $this->form($this->companies->find(CurrentUser::user(), (int) $request->attribute('id')), [], []);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->companies->update(CurrentUser::user(), $id, $request->all());
            $this->customFields->saveValues('company', $id, (array) $request->input('custom', []));
        } catch (ValidationException $e) {
            return $this->form($this->companies->find(CurrentUser::user(), $id), $e->errors, $request->all(), 422);
        }

        Session::setFlash('success', t('companies.flash.updated'));

        return $this->redirect('/companies/' . $id);
    }

    public function delete(Request $request): Response
    {
        try {
            $this->companies->delete(CurrentUser::user(), (int) $request->attribute('id'));
            Session::setFlash('success', t('companies.flash.deleted'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t('companies.error.has_contacts'));
        }

        return $this->redirect('/companies');
    }

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->companies->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/companies');
        if (!str_starts_with($back, '/companies') || str_contains($back, '//')) {
            $back = '/companies';
        }

        return $this->redirect($back);
    }

    // ---- Categories ------------------------------------------------

    public function categoriesPage(Request $request): Response
    {
        if (!$this->categories->canManage(CurrentUser::user())) {
            throw new AuthorizationException();
        }

        return $this->viewWithLayout('crm/companies/categories', 'layout', [
            'categories' => $this->categories->all(),
        ]);
    }

    public function createCategory(Request $request): Response
    {
        try {
            $this->categories->create(CurrentUser::user(), (string) $request->input('name', ''));
            Session::setFlash('success', t('companies.flash.category_added'));
        } catch (ValidationException $e) {
            Session::setFlash('error', t((string) (array_values($e->errors)[0][0] ?? 'companies.error.category_name')));
        }

        return $this->redirect('/companies/categories');
    }

    public function deleteCategory(Request $request): Response
    {
        $this->categories->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', t('companies.flash.category_deleted'));

        return $this->redirect('/companies/categories');
    }

    // ---- Internals -------------------------------------------------

    private function form(?array $company, array $errors, array $old, int $status = 200): Response
    {
        $user = CurrentUser::user();
        $isNew = $company === null;

        return $this->viewWithLayout('crm/companies/form', 'layout', [
            'company' => $company,
            'errors' => $errors,
            'old' => $old,
            'categories' => $this->categories->all(),
            'canManageCategories' => $this->categories->canManage($user),
            'customFieldDefs' => $this->customFields->definitionsFor('company'),
            'customFieldValues' => $isNew ? [] : $this->customFields->valuesFor('company', (int) $company['id']),
            // Only on create: contacts the user may edit, to link to the new company.
            'contactOptions' => $isNew ? $this->assignableContacts($user) : [],
        ], $status);
    }

    /** @return array<int, array{id: int, name: string}> */
    private function assignableContacts(array $user): array
    {
        try {
            $rows = $this->contactService->list($user);
        } catch (AuthorizationException) {
            return [];
        }
        $allowed = $this->contactService->rowPermissions($user, $rows);

        $options = [];
        foreach ($rows as $row) {
            if (!empty($allowed[(int) $row['id']]['edit'])) {
                $name = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
                $extra = (string) ($row['phone'] ?? '') !== '' ? $row['phone'] : (string) ($row['email'] ?? '');
                $options[] = ['id' => (int) $row['id'], 'name' => $extra !== '' ? $name . ' — ' . $extra : $name];
            }
        }

        return $options;
    }
}
