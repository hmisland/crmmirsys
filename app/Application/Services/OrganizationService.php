<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Validation\Validator;

final class OrganizationService extends BaseService
{
    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly OrganizationRepository $organizations,
    ) {
        parent::__construct($logger, $events);
    }

    /**
     * Slug is derived from the name, made unique with a numeric
     * suffix if needed. Used for a future public-facing identifier
     * (e.g. a subdomain) — not load-bearing yet, but cheap to get
     * right now rather than retrofit once something depends on it.
     */
    public function create(string $name): int
    {
        $validator = Validator::make(['name' => $name], ['name' => 'required|min:2']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $slug = $this->uniqueSlug($name);

        return $this->organizations->create([
            'name' => trim($name),
            'slug' => $slug,
        ]);
    }

    public function find(int $organizationId, array $actingUser): array
    {
        $this->assertCanAccess($organizationId, $actingUser);

        $organization = $this->organizations->findById($organizationId);

        if ($organization === null) {
            throw new NotFoundException('Organization not found.');
        }

        return $organization;
    }

    public function update(int $organizationId, array $actingUser, array $input): array
    {
        $this->assertCanAccess($organizationId, $actingUser);

        $validator = Validator::make($input, ['name' => 'required|min:2']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $allowedLanguages = ['en', 'fa', 'ar', 'tr'];
        $fields = [
            'name' => trim((string) $input['name']),
        ];

        if (in_array($input['default_language'] ?? null, $allowedLanguages, true)) {
            $fields['default_language'] = $input['default_language'];
        }

        if (isset($input['default_timezone']) && $input['default_timezone'] !== '') {
            $fields['default_timezone'] = (string) $input['default_timezone'];
        }

        $this->organizations->updateOrganization($organizationId, $fields);

        return $this->organizations->findById($organizationId);
    }

    /**
     * Super Admin can access any organization; anyone else only their
     * own. This is a deliberately simple, explicit check rather than
     * going through AuthorizationEngine — "is this MY organization" is
     * an identity fact, not a permission grant.
     */
    private function assertCanAccess(int $organizationId, array $actingUser): void
    {
        if (!empty($actingUser['is_super_admin'])) {
            return;
        }

        if ((int) ($actingUser['organization_id'] ?? 0) !== $organizationId) {
            throw new AuthorizationException('You do not have access to this organization.');
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name), '-'));
        $base = $base !== '' ? $base : 'organization';

        $slug = $base;
        $suffix = 1;

        while ($this->organizations->findBySlug($slug) !== null) {
            $suffix++;
            $slug = $base . '-' . $suffix;
        }

        return $slug;
    }
}
