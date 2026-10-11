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
use App\Core\Container;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Authorization\Scope;
use App\Infrastructure\Modules\FollowUpAccess;
use App\Infrastructure\Modules\ModuleAccessResolver;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactLinkRepository;
use App\Infrastructure\Repositories\ModuleRepository;
use App\Infrastructure\Repositories\OrganizationModuleRepository;
use App\Infrastructure\Repositories\UserModuleOverrideRepository;
use App\Infrastructure\Security\CurrentUser;
use App\Infrastructure\Security\Session;
use App\Infrastructure\Theme\TemplateEngine;

use function App\Infrastructure\Theme\t;

final class ContactController extends BaseController
{
    private const EMPTY_RELATED = ['phones' => [], 'emails' => [], 'social' => [], 'interests' => []];

    /**
     * Records other modules attach to a contact, shown as summary tiles
     * on the contact page; each list opens in a dialog on demand.
     * type => [module key, view permission, icon, label key, icon tone]
     */
    private const RELATED_SOURCES = [
        'invoices' => ['finance', Permission::INVOICES_VIEW, 'receipt', 'invoices.title', 'success'],
        'proformas' => ['finance', Permission::QUOTES_VIEW, 'file-text', 'proformas.title', 'info'],
        'deals' => ['sales', Permission::DEALS_VIEW, 'briefcase', 'nav.deals', ''],
        'tasks' => ['sales', Permission::TASKS_VIEW, 'check-square', 'nav.tasks', 'warning'],
        'followups' => ['followups', Permission::FOLLOWUPS_VIEW, 'calendar-check', 'followups.title', 'warning'],
        'appointments' => ['appointments', Permission::APPOINTMENTS_VIEW, 'calendar', 'appointments.title', 'info'],
        'tickets' => ['support', Permission::TICKETS_VIEW, 'life-buoy', 'nav.tickets', 'danger'],
    ];

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
        private readonly ?Container $container = null,
    ) {
        parent::__construct($templates);
    }

    public function index(Request $request): Response
    {
        $categoryId = (string) $request->query('category_id', '');
        $mine = (string) $request->query('mine', '') === '1';
        $filters = [
            'search' => trim((string) $request->query('q', '')),
            'category_id' => $categoryId !== '' ? $categoryId : null,
            'owner_id' => $mine ? (int) CurrentUser::id() : null,
        ];

        // Database-side paging: only the current page is read.
        $pagination = Pagination::fromRequest($request, $this->contacts->count(CurrentUser::user(), $filters));
        $contacts = $this->contacts->listPage(CurrentUser::user(), $filters, $pagination->perPage, $pagination->offset());

        $engine = $this->authorizationEngine();

        // Per-row edit/delete: the same record-level check the Service
        // enforces (organization scope = every contact, own = only the
        // contacts the user owns), so a disabled button never hides an
        // action that would actually be allowed — or vice versa.
        $rowActions = [];
        foreach ($contacts as $contact) {
            $rowActions[(int) $contact['id']] = [
                'edit' => $engine !== null && $engine->can(CurrentUser::user(), Permission::CONTACTS_EDIT, $contact),
                'delete' => $engine !== null && $engine->can(CurrentUser::user(), Permission::CONTACTS_DELETE, $contact),
            ];
        }

        return $this->viewWithLayout('crm/contacts/index', 'layout', [
            'contacts' => $contacts,
            'rowActions' => $rowActions,
            'pagination' => $pagination,
            'q' => (string) $request->query('q', ''),
            'mine' => $mine,
            'categories' => $this->categories->all(),
            'categoryId' => $categoryId,
            'canImport' => $engine !== null && $engine->can(CurrentUser::user(), Permission::CONTACTS_IMPORT),
            'canExport' => $engine !== null && $engine->can(CurrentUser::user(), Permission::CONTACTS_EXPORT),
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
            'clientRecord' => $this->clientRecordLink($id),
            'relatedSummary' => $this->relatedSummary($id),
            'canCreateFollowUp' => FollowUpAccess::canCreate($this->authorizationEngine(), CurrentUser::user()),
        ]);
    }

    /**
     * The HTML fragment for one related list (loaded into the contact
     * page's dialog). Same module + permission gate as the tile.
     */
    public function related(Request $request): Response
    {
        $id = (int) $request->attribute('id');
        $type = (string) $request->attribute('type');
        $this->contacts->find(CurrentUser::user(), $id); // contacts.view + existence

        $access = $this->relatedAccess();
        if (!array_key_exists($type, $access)) {
            throw new NotFoundException();
        }

        return $this->view('crm/contacts/_related-panel', [
            'type' => $type,
            'rows' => (new ContactLinkRepository())->rows($type, $id, $access[$type]),
        ]);
    }

    /**
     * @return array<int, array{type: string, icon: string, label_key: string, tone: string, count: int}>
     */
    private function relatedSummary(int $contactId): array
    {
        $access = $this->relatedAccess();
        if ($access === []) {
            return [];
        }

        $counts = (new ContactLinkRepository())->counts($contactId, array_keys($access), $access);
        $summary = [];

        foreach (array_keys($access) as $type) {
            [, , $icon, $labelKey, $tone] = self::RELATED_SOURCES[$type];
            $summary[] = ['type' => $type, 'icon' => $icon, 'label_key' => $labelKey, 'tone' => $tone, 'count' => $counts[$type] ?? 0];
        }

        return $summary;
    }

    /**
     * Related types the current user may see: module entitled + view
     * permission held. Value = owner id to filter by when the user's
     * scope is OWN, else null.
     *
     * @return array<string, int|null>
     */
    private function relatedAccess(): array
    {
        $engine = $this->authorizationEngine();
        if ($engine === null) {
            return [];
        }

        $user = CurrentUser::user();
        $resolver = new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository());
        $moduleEnabled = [];
        $access = [];

        foreach (self::RELATED_SOURCES as $type => [$module, $permission]) {
            $moduleEnabled[$module] ??= $resolver->resolvedForUser($module, $user);
            $scope = $moduleEnabled[$module] ? $engine->scopeFor($user, $permission) : null;

            if ($scope !== null) {
                $access[$type] = $scope === Scope::OWN ? (int) $user['id'] : null;
            }
        }

        return $access;
    }

    private function authorizationEngine(): ?AuthorizationEngine
    {
        return $this->container !== null && $this->container->has(AuthorizationEngine::class)
            ? $this->container->make(AuthorizationEngine::class)
            : null;
    }

    /**
     * Optional "Client record" button, only when the Client Records
     * module is installed, entitled for this user and they can view
     * records — the same lazy has()/make() pattern the Dashboard uses,
     * so Core never hard-depends on the module. null = no button.
     *
     * @return array{exists: bool}|null
     */
    private function clientRecordLink(int $contactId): ?array
    {
        $serviceClass = \Modules\ClientRecords\Services\ClientRecordService::class;
        $user = CurrentUser::user();

        if ($this->container === null || !$this->container->has($serviceClass)) {
            return null;
        }

        $enabled = (new ModuleAccessResolver(new ModuleRepository(), new OrganizationModuleRepository(), new UserModuleOverrideRepository()))
            ->resolvedForUser('client_records', $user);
        $engine = $this->container->make(AuthorizationEngine::class);

        if (!$enabled || !$engine->can($user, Permission::CLIENT_RECORDS_VIEW)) {
            return null;
        }

        $exists = $this->container->make($serviceClass)->findForContact($user, $contactId) !== null;

        if (!$exists && !$engine->can($user, Permission::CLIENT_RECORDS_CREATE)) {
            return null;
        }

        return ['exists' => $exists];
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

    /** Bulk delete from the list page; returns to the same filtered page. */
    public function bulkDelete(Request $request): Response
    {
        $result = $this->contacts->bulkDelete(CurrentUser::user(), (array) $request->input('ids', []));
        Session::setFlash(
            $result['deleted'] > 0 ? 'success' : 'error',
            t('bulk.deleted_summary', ['deleted' => $result['deleted'], 'skipped' => $result['skipped']])
        );

        $back = (string) $request->input('return_to', '/contacts');
        if (!str_starts_with($back, '/contacts') || str_contains($back, '//')) {
            $back = '/contacts';
        }

        return $this->redirect($back);
    }
}
