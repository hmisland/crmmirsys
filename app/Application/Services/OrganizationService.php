<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Core\Events\EventBus;
use App\Core\Exceptions\AuthorizationException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Infrastructure\Authorization\AuthorizationEngine;
use App\Infrastructure\Authorization\Permission;
use App\Infrastructure\Logging\LoggerInterface;
use App\Infrastructure\Repositories\OrganizationRepository;
use App\Infrastructure\Time\OrganizationTimezone;
use App\Infrastructure\Validation\Validator;

final class OrganizationService extends BaseService
{
    /** Seller details printed on invoices/proformas (all optional). */
    private const PROFILE_FIELDS = [
        'address' => 500, 'postal_code' => 20, 'phone' => 50,
        'national_id' => 30, 'economic_code' => 30, 'registration_number' => 50,
    ];
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
    private const LOGO_MAX_BYTES = 1024 * 1024;

    public function __construct(
        LoggerInterface $logger,
        EventBus $events,
        private readonly OrganizationRepository $organizations,
        private readonly ?AuthorizationEngine $authorization = null,
        private readonly ?string $storageRoot = null,
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
            'default_timezone' => OrganizationTimezone::shared()->defaultIdentifier(),
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

    /**
     * @param array|null $logo an uploaded file (Request::file('logo')), or null to leave the logo as is
     */
    public function update(int $organizationId, array $actingUser, array $input, ?array $logo = null): array
    {
        $this->assertCanAccess($organizationId, $actingUser);
        $this->assertCanEdit($actingUser);

        $validator = Validator::make($input, ['name' => 'required|min:2']);

        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $allowedLanguages = ['en', 'fa', 'ar', 'tr', 'fr'];
        $fields = [
            'name' => trim((string) $input['name']),
        ];

        if (in_array($input['default_language'] ?? null, $allowedLanguages, true)) {
            $fields['default_language'] = $input['default_language'];
        }

        // Strict IANA identifier (no "+03:30"); empty keeps the current zone.
        $timezone = trim((string) ($input['default_timezone'] ?? ''));
        if ($timezone !== '') {
            if (!OrganizationTimezone::isValid($timezone)) {
                throw new ValidationException(['default_timezone' => ['org.timezone_invalid']]);
            }
            $fields['default_timezone'] = $timezone;
        }

        // The settings form always posts every profile field, so an
        // empty value genuinely clears it.
        foreach (self::PROFILE_FIELDS as $field => $maxLength) {
            if (array_key_exists($field, $input)) {
                $value = trim((string) $input[$field]);
                $fields[$field] = $value === '' ? null : mb_substr($value, 0, $maxLength);
            }
        }

        $organization = $this->organizations->findById($organizationId);

        if ($logo !== null) {
            $fields['logo_path'] = $this->storeLogo($organizationId, $logo);
            $this->deleteLogoFile($organization['logo_path'] ?? null);
        } elseif (!empty($input['remove_logo'])) {
            $fields['logo_path'] = null;
            $this->deleteLogoFile($organization['logo_path'] ?? null);
        }

        $this->organizations->updateOrganization($organizationId, $fields);
        OrganizationTimezone::shared()->forget($organizationId);

        return $this->organizations->findById($organizationId);
    }

    /** Absolute path of the organization's logo file, or null when it has none. */
    public function logoPath(array $organization): ?string
    {
        if (empty($organization['logo_path']) || $this->storageRoot === null) {
            return null;
        }

        $path = rtrim($this->storageRoot, '/\\') . '/' . $organization['logo_path'];

        return is_file($path) ? $path : null;
    }

    public function canEdit(array $actingUser): bool
    {
        return !empty($actingUser['is_super_admin'])
            || ($this->authorization !== null && $this->authorization->can($actingUser, Permission::ORGANIZATION_EDIT));
    }

    /**
     * The settings page was only guarded by organization.view; the
     * POST that saves it had no permission check at all, so any member
     * could rename the organization. Saving now needs organization.edit.
     */
    private function assertCanEdit(array $actingUser): void
    {
        if (!$this->canEdit($actingUser)) {
            throw new AuthorizationException();
        }
    }

    /**
     * Same storage rules as attachments: outside the web root, random
     * file name, type decided by the real image content (getimagesize),
     * never by the browser's Content-Type or the original name. Only
     * PNG/JPEG, because those embed reliably in PDFs.
     */
    private function storeLogo(int $organizationId, array $file): string
    {
        if ($this->storageRoot === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ValidationException(['logo' => ['org.logo_upload_failed']]);
        }

        if ((int) $file['size'] > self::LOGO_MAX_BYTES) {
            throw new ValidationException(['logo' => ['org.logo_too_large']]);
        }

        $info = @getimagesize((string) $file['tmp_name']);
        $extension = is_array($info) ? (self::LOGO_TYPES[$info['mime']] ?? null) : null;

        if ($extension === null) {
            throw new ValidationException(['logo' => ['org.logo_invalid_type']]);
        }

        $relativeDir = 'uploads/' . $organizationId . '/branding';
        $absoluteDir = rtrim($this->storageRoot, '/\\') . '/' . $relativeDir;
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $relativePath = $relativeDir . '/logo-' . bin2hex(random_bytes(12)) . '.' . $extension;

        if (!move_uploaded_file((string) $file['tmp_name'], rtrim($this->storageRoot, '/\\') . '/' . $relativePath)) {
            throw new ValidationException(['logo' => ['org.logo_upload_failed']]);
        }

        return $relativePath;
    }

    private function deleteLogoFile(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '' || $this->storageRoot === null || str_contains($relativePath, '..')) {
            return;
        }

        $path = rtrim($this->storageRoot, '/\\') . '/' . $relativePath;
        if (is_file($path)) {
            @unlink($path);
        }
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
