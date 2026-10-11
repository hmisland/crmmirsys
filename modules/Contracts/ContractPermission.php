<?php

declare(strict_types=1);

namespace Modules\Contracts;

/**
 * The Contracts module's permission keys. They live here, not in Core's
 * Permission.php: ContractsModule::register() hands them to
 * Permission::registerModuleKeys(), and default grants come from this
 * module's own migration (0003). Scopes (own/team/department/
 * organization) are the role's choice, as for every other key.
 */
final class ContractPermission
{
    public const VIEW = 'contracts.view';
    public const CREATE = 'contracts.create';
    /** Draft / pending-approval contracts only (enforced by the service, step 2.4). */
    public const EDIT = 'contracts.edit';
    public const DELETE = 'contracts.delete';
    public const APPROVE = 'contracts.approve';
    /** Activation assigns the number and the signing date. */
    public const ACTIVATE = 'contracts.activate';
    public const TERMINATE = 'contracts.terminate';
    public const RENEW = 'contracts.renew';
    /** Categories and the organization's auto-create setting. */
    public const MANAGE_SETTINGS = 'contracts.manage_settings';

    public const ALL = [
        self::VIEW, self::CREATE, self::EDIT, self::DELETE, self::APPROVE,
        self::ACTIVATE, self::TERMINATE, self::RENEW, self::MANAGE_SETTINGS,
    ];
}
