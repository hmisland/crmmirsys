<?php

declare(strict_types=1);

namespace Modules\Marketing;

/**
 * The Marketing module's permission keys, registered from
 * MarketingModule::register(); default grants come from migration 0006.
 *
 *  - view: dashboard, campaigns, history, analytics;
 *  - create: create and edit draft campaigns (and test-send them);
 *  - send: approve and send a campaign, cancel it, retry failures —
 *    the approval step: a draft only goes out when someone holding this
 *    key confirms it;
 *  - manage_templates: reusable message templates;
 *  - manage_suppressions: the opt-out / suppression list.
 * Provider credentials are NOT here: they live in Organization settings
 * (organization.edit).
 */
final class MarketingPermission
{
    public const VIEW = 'marketing.view';
    public const CREATE = 'marketing.create';
    public const SEND = 'marketing.send';
    public const MANAGE_TEMPLATES = 'marketing.manage_templates';
    public const MANAGE_SUPPRESSIONS = 'marketing.manage_suppressions';
    /** Marketing 3.1 (F2): choose the "transactional" type, which skips marketing opt-outs. org_admin only by default (migration 0007). */
    public const SEND_TRANSACTIONAL = 'marketing.send_transactional';
    /** Phase 2: create, edit, delete segments and static memberships. org_admin only by default (migration 0012). */
    public const MANAGE_SEGMENTS = 'marketing.manage_segments';

    public const ALL = [self::VIEW, self::CREATE, self::SEND, self::MANAGE_TEMPLATES, self::MANAGE_SUPPRESSIONS, self::SEND_TRANSACTIONAL, self::MANAGE_SEGMENTS];
}
