<?php

declare(strict_types=1);

namespace App\Infrastructure\Records;

/**
 * A kind of record that shared Core features (notes, tags, attachments,
 * timeline, related panels) can attach to: "contact", "company",
 * "lead" from Core, and later module-owned ones such as "contract".
 */
final class RecordType
{
    /**
     * @param string      $key            stored in polymorphic columns (notable_type, related_type, ...)
     * @param string|null $module         owning module key; null = Core
     * @param string|null $viewPermission permission needed to view a record of this type
     * @param string      $labelKey       translation key for the type's name
     * @param string      $showPath       record page, "{id}" is replaced (e.g. "/contacts/{id}")
     * @param \Closure(int): ?array|null $finder loads one record by id through
     *        the owning module's tenant-scoped repository; optional — a type
     *        without one simply can't be looked up by other modules
     * @param bool $recordSupport whether Core's shared notes/tags/attachments
     *        routes (/records/{type}/{id}/...) accept this type; a module that
     *        registers a type only to be linkable (e.g. Sales' `deal`, which has
     *        its own notes) passes false so those routes keep 404ing for it
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $module,
        public readonly ?string $viewPermission,
        public readonly string $labelKey,
        public readonly string $showPath,
        public readonly ?string $icon = null,
        public readonly ?\Closure $finder = null,
        public readonly bool $recordSupport = true,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) !== 1) {
            throw new \InvalidArgumentException("Invalid record type key [{$key}].");
        }
    }

    public function showUrl(int $id): string
    {
        return str_replace('{id}', (string) $id, $this->showPath);
    }
}
