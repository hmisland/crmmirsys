<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\AttachmentService;
use App\Application\Services\ContactCategoryService;
use App\Application\Services\ContactService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

final class ContactController extends BaseController
{
    private const EMPTY_RELATED = ['phones' => [], 'emails' => [], 'social' => [], 'interests' => []];

    public function __construct(
        TemplateEngine $templates,
        private readonly ContactService $contacts,
        private readonly NoteService $notes,
        private readonly TagService $tags,
        private readonly TimelineService $timeline,
        private readonly CustomFieldService $customFields,
        private readonly CompanyRepository $companies,
        private readonly AttachmentService $attachments,
        private readonly ContactCategoryService $categories,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $categoryId = (string) $request->query('category_id', '');
        $contacts = $this->contacts->list(CurrentUser::user(), [
            'search' => (string) $request->query('q', ''),
            'category_id' => $categoryId !== '' ? $categoryId : null,
        ]);

        return $this->viewWithLayout('crm/contacts/index', 'layout', [
            'contacts' => $contacts,
            'q' => (string) $request->query('q', ''),
            'categories' => $this->categories->all(),
            'categoryId' => $categoryId,
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->viewWithLayout('crm/contacts/form', 'layout', [
            'contact' => null,
            'companies' => $this->companies->all(),
            'categories' => $this->categories->all(),
            'related' => self::EMPTY_RELATED,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $contact = $this->contacts->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/contacts/form', 'layout', [
                'contact' => null,
                'companies' => $this->companies->all(),
                'categories' => $this->categories->all(),
                'related' => self::EMPTY_RELATED,
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        return $this->redirect('/contacts/' . $contact['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $contact = $this->contacts->find(CurrentUser::user(), $id);
        $company = $contact['company_id'] !== null ? $this->companies->findById((int) $contact['company_id']) : null;
        $category = $contact['category_id'] !== null ? $this->categories->find((int) $contact['category_id']) : null;

        return $this->viewWithLayout('crm/contacts/show', 'layout', [
            'contact' => $contact,
            'company' => $company,
            'category' => $category,
            'related' => $this->contacts->relatedData($id),
            'notes' => $this->notes->forSubject('contact', $id),
            'tags' => $this->tags->forSubject('contact', $id),
            'allTags' => $this->tags->allForOrganization(),
            'timeline' => $this->timeline->forSubject('contact', $id),
            'customFieldDefs' => $this->customFields->definitionsFor('contact'),
            'customFieldValues' => $this->customFields->valuesFor('contact', $id),
            'attachments' => $this->attachments->forSubject('contact', $id),
        ]);
    }

    public function showEdit(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $contact = $this->contacts->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('crm/contacts/form', 'layout', [
            'contact' => $contact,
            'companies' => $this->companies->all(),
            'categories' => $this->categories->all(),
            'related' => $this->contacts->relatedData($id),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->contacts->update(CurrentUser::user(), $id, $request->all());
            $this->customFields->saveValues('contact', $id, (array) $request->input('custom', []));
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/contacts/form', 'layout', [
                'contact' => $this->contacts->find(CurrentUser::user(), $id),
                'companies' => $this->companies->all(),
                'categories' => $this->categories->all(),
                'related' => $this->contacts->relatedData($id),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Contact updated.');

        return $this->redirect('/contacts/' . $id);
    }

    public function delete(Request $request): Response
    {
        $this->contacts->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Contact deleted.');

        return $this->redirect('/contacts');
    }

    public function categoriesPage(Request $request): Response
    {
        return $this->viewWithLayout('crm/contacts/categories', 'layout', [
            'categories' => $this->categories->all(),
        ]);
    }

    public function createCategory(Request $request): Response
    {
        try {
            $this->categories->create(CurrentUser::user(), (string) $request->input('name', ''));
            Session::setFlash('success', 'Category added.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not add category.');
        }

        return $this->redirect('/contacts/categories');
    }

    public function deleteCategory(Request $request): Response
    {
        $this->categories->delete(CurrentUser::user(), (int) $request->attribute('id'));
        Session::setFlash('success', 'Category deleted.');

        return $this->redirect('/contacts/categories');
    }
}
