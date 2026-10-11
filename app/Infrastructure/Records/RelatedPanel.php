<?php

declare(strict_types=1);

namespace App\Infrastructure\Records;

/**
 * A box a module contributes to another record's page — e.g. later a
 * "Contracts" panel on the deal page, without Sales knowing Contracts.
 *
 * $render receives the subject record (the row the host page already
 * loaded and authorized) and the current user, and returns HTML. The
 * module renders its own template and must escape its own output and
 * authorize its own rows (e.g. own-scope filtering) inside $render.
 */
final class RelatedPanel
{
    /**
     * @param string      $subjectType record type of the host page ("deal", "contact", ...)
     * @param string      $id          unique per subject type, e.g. "contracts.deal"
     * @param string|null $module      owning module key; null = Core
     * @param string      $titleKey    translation key for the panel heading
     * @param \Closure(array, array): string $render
     * @param int         $order       lower first; ties by id
     * @param string|null $permission  permission needed to see the panel
     */
    public function __construct(
        public readonly string $subjectType,
        public readonly string $id,
        public readonly ?string $module,
        public readonly string $titleKey,
        public readonly \Closure $render,
        public readonly int $order = 100,
        public readonly ?string $permission = null,
        public readonly ?string $icon = null,
    ) {
    }
}
