<?php

declare(strict_types=1);

namespace App\Application\Controllers\Api;

use App\Application\Services\ContactService;
use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Pagination\Pagination;
use App\Infrastructure\Repositories\CompanyRepository;
use App\Infrastructure\Repositories\ContactCategoryRepository;
use App\Infrastructure\Security\CurrentUser;

/**
 * Read-only contacts API (Phase 1):
 *
 *   GET /api/v1/contacts        paginated, filterable list
 *   GET /api/v1/contacts/{id}   one contact with phones, emails, social profiles, interests
 *
 * Same ContactService, same `contacts.view` permission and the same
 * organization scoping as the web pages (two front doors, one service
 * layer): the organization is always the authenticated user's own, never a
 * client-supplied value, and a contact of another organization is a 404.
 * Works for cookie sessions and Bearer tokens alike. No writes yet.
 */
final class ContactApiController extends BaseApiController
{
    public function __construct(
        private readonly ContactService $contacts,
        private readonly CompanyRepository $companies,
        private readonly ContactCategoryRepository $categories,
    ) {
    }

    public function index(Request $request): Response
    {
        $user = CurrentUser::user();
        $filters = [
            'search' => trim((string) $request->query('q', '')),
            'company_id' => $this->positiveInt($request->query('company_id')),
            'category_id' => $this->positiveInt($request->query('category_id')),
            'owner_id' => $this->positiveInt($request->query('owner_id')),
        ];

        $pagination = Pagination::fromRequest($request, $this->contacts->count($user, $filters));
        $rows = $this->contacts->listPage($user, $filters, $pagination->perPage, $pagination->offset());

        return $this->paginated(
            array_map(static fn (array $row): array => ContactApiPresenter::summary($row), $rows),
            ContactApiPresenter::paginationMeta($pagination)
        );
    }

    public function show(Request $request): Response
    {
        $user = CurrentUser::user();
        $id = (int) $request->attribute('id');

        $contact = $this->contacts->find($user, $id);

        $company = $contact['company_id'] !== null ? $this->companies->findById((int) $contact['company_id']) : null;
        $category = $contact['category_id'] !== null ? $this->categories->findById((int) $contact['category_id']) : null;

        return $this->data(ContactApiPresenter::detail($contact, $company, $category, $this->contacts->relatedData($id)));
    }

    private function positiveInt(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $int === false ? null : $int;
    }
}
