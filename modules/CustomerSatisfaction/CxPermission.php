<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction;

/**
 * Customer Satisfaction permission keys, registered from
 * CustomerSatisfactionModule::register(); default grants: migration 0002.
 *  - view: surveys, invitations and responses;
 *  - manage: create/edit surveys, close follow-ups.
 */
final class CxPermission
{
    public const VIEW = 'cx.view';
    public const MANAGE = 'cx.manage';

    public const ALL = [self::VIEW, self::MANAGE];
}
