<?php

declare(strict_types=1);

namespace Modules\Projects;

/**
 * The Projects module's permission keys. Same pattern as Contracts:
 * ProjectsModule::register() hands them to Permission::registerModuleKeys(),
 * default grants come from this module's own migration (0003). Scopes
 * (own/team/department/organization) are the role's choice.
 */
final class ProjectPermission
{
    public const VIEW = 'projects.view';
    public const CREATE = 'projects.create';
    /** Fields, status changes, and links to deals/contracts. */
    public const EDIT = 'projects.edit';
    /** Draft / planned / cancelled projects only (enforced by the service). */
    public const DELETE = 'projects.delete';
    public const MANAGE_MEMBERS = 'projects.manage_members';
    /** The organization's auto-create setting. */
    public const MANAGE_SETTINGS = 'projects.manage_settings';

    public const ALL = [
        self::VIEW, self::CREATE, self::EDIT, self::DELETE, self::MANAGE_MEMBERS, self::MANAGE_SETTINGS,
    ];
}
