<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Application\Services\AttachmentService;
use App\Application\Services\CompanyService;
use App\Application\Services\CustomFieldService;
use App\Application\Services\NoteService;
use App\Application\Services\TagService;
use App\Application\Services\TimelineService;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Repositories\ContactRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

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
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $companies = $this->companies->list(CurrentUser::user(), ['search' => (string) $request->query('q', '')]);

        return $this->viewWithLayout('crm/companies/index', 'layout', [
            'companies' => $companies,
            'q' => (string) $request->query('q', ''),
        ]);
    }

    public function showCreate(Request $request): Response
    {
        return $this->viewWithLayout('crm/companies/form', 'layout', [
            'company' => null,
            'errors' => [],
            'old' => [],
        ]);
    }

    public function create(Request $request): Response
    {
        try {
            $company = $this->companies->create(CurrentUser::user(), $request->all());
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/companies/form', 'layout', [
                'company' => null,
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        return $this->redirect('/companies/' . $company['id']);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $company = $this->companies->find(CurrentUser::user(), $id);

        return $this->viewWithLayout('crm/companies/show', 'layout', [
            'company' => $company,
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
        $id = (int) $request->attribute('id');

        return $this->viewWithLayout('crm/companies/form', 'layout', [
            'company' => $this->companies->find(CurrentUser::user(), $id),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->attribute('id');

        try {
            $this->companies->update(CurrentUser::user(), $id, $request->all());
            $this->customFields->saveValues('company', $id, (array) $request->input('custom', []));
        } catch (ValidationException $e) {
            return $this->viewWithLayout('crm/companies/form', 'layout', [
                'company' => $this->companies->find(CurrentUser::user(), $id),
                'errors' => $e->errors,
                'old' => $request->all(),
            ], 422);
        }

        Session::setFlash('success', 'Company updated.');

        return $this->redirect('/companies/' . $id);
    }

    public function delete(Request $request): Response
    {
        try {
            $this->companies->delete(CurrentUser::user(), (int) $request->attribute('id'));
            Session::setFlash('success', 'Company deleted.');
        } catch (ValidationException $e) {
            Session::setFlash('error', array_values($e->errors)[0][0] ?? 'Could not delete company.');
        }

        return $this->redirect('/companies');
    }
}
