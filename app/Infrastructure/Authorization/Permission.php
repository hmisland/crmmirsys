<?php

declare(strict_types=1);

namespace App\Infrastructure\Authorization;

/**
 * Per Phase 0 §16: the permission catalogue is fixed and code-defined
 * — what's assignable to a role (role_permissions.scope) is editable,
 * the list of keys themselves is not. Each module registers its own
 * keys here when it's built; Phase 3 only introduces the keys for
 * what Phase 3 itself manages (organization settings, users,
 * departments, teams, roles). Sales/Wallet/etc. add theirs starting
 * Phase 5+ — this class grows, it isn't rewritten.
 */
final class Permission
{
    public const ORGANIZATION_VIEW = 'organization.view';
    public const ORGANIZATION_EDIT = 'organization.edit';

    public const USERS_VIEW = 'users.view';
    public const USERS_CREATE = 'users.create';
    public const USERS_EDIT = 'users.edit';
    public const USERS_DELETE = 'users.delete';
    public const USERS_ACTIVATE = 'users.activate';
    public const USERS_DEACTIVATE = 'users.deactivate';

    public const DEPARTMENTS_VIEW = 'departments.view';
    public const DEPARTMENTS_CREATE = 'departments.create';
    public const DEPARTMENTS_EDIT = 'departments.edit';
    public const DEPARTMENTS_DELETE = 'departments.delete';

    public const TEAMS_VIEW = 'teams.view';
    public const TEAMS_CREATE = 'teams.create';
    public const TEAMS_EDIT = 'teams.edit';
    public const TEAMS_DELETE = 'teams.delete';

    public const ROLES_VIEW = 'roles.view';
    public const ROLES_CREATE = 'roles.create';
    public const ROLES_EDIT = 'roles.edit';
    public const ROLES_DELETE = 'roles.delete';
    public const ROLES_ASSIGN = 'roles.assign';

    // Phase 4
    public const MODULES_MANAGE = 'modules.manage';

    // Phase 5 — CRM Core
    public const CONTACTS_VIEW = 'contacts.view';
    public const CONTACTS_CREATE = 'contacts.create';
    public const CONTACTS_EDIT = 'contacts.edit';
    public const CONTACTS_DELETE = 'contacts.delete';
    public const CONTACTS_EXPORT = 'contacts.export';
    public const CONTACTS_IMPORT = 'contacts.import';
    public const CONTACTS_MANAGE_CATEGORIES = 'contacts.manage_categories';

    /**
     * The only permissions an Organization Admin may grant to an
     * individual user on top of their role (user_permission_grants).
     */
    public const USER_GRANTABLE = [self::CONTACTS_IMPORT, self::CONTACTS_EXPORT];

    // Phase 12 — Finance (registered upfront per the sub-phase plan; most are granted to roles as each sub-phase actually builds that feature)
    public const FINANCE_SETTINGS_VIEW = 'finance.settings.view';
    public const FINANCE_SETTINGS_EDIT = 'finance.settings.edit';
    public const PRODUCTS_VIEW = 'products.view';
    public const PRODUCTS_CREATE = 'products.create';
    public const PRODUCTS_EDIT = 'products.edit';
    public const PRODUCTS_DELETE = 'products.delete';
    public const QUOTES_VIEW = 'quotes.view';
    public const QUOTES_CREATE = 'quotes.create';
    public const QUOTES_EDIT = 'quotes.edit';
    public const QUOTES_DELETE = 'quotes.delete';
    public const QUOTES_CONVERT = 'quotes.convert';
    public const INVOICES_VIEW = 'invoices.view';
    public const INVOICES_CREATE = 'invoices.create';
    public const INVOICES_EDIT = 'invoices.edit';
    public const INVOICES_DELETE = 'invoices.delete';
    public const INVOICES_ISSUE = 'invoices.issue';
    public const INVOICES_CANCEL = 'invoices.cancel';
    public const PAYMENTS_VIEW = 'payments.view';
    public const PAYMENTS_CREATE = 'payments.create';
    public const PAYMENTS_REFUND = 'payments.refund';
    public const CREDIT_NOTES_VIEW = 'credit_notes.view';
    public const CREDIT_NOTES_CREATE = 'credit_notes.create';
    public const TAX_VIEW = 'tax.view';
    public const TAX_MANAGE = 'tax.manage';
    public const FINANCIAL_REPORTS_VIEW = 'financial_reports.view';
    public const FINANCIAL_PERIODS_VIEW = 'financial_periods.view';
    public const FINANCIAL_PERIODS_CLOSE = 'financial_periods.close';
    public const FINANCIAL_PERIODS_REOPEN = 'financial_periods.reopen';

    public const COMPANIES_VIEW = 'companies.view';
    public const COMPANIES_CREATE = 'companies.create';
    public const COMPANIES_EDIT = 'companies.edit';
    public const COMPANIES_DELETE = 'companies.delete';
    public const COMPANIES_MANAGE_CATEGORIES = 'companies.manage_categories';

    public const LEADS_VIEW = 'leads.view';
    public const LEADS_CREATE = 'leads.create';
    public const LEADS_EDIT = 'leads.edit';
    public const LEADS_DELETE = 'leads.delete';
    public const LEADS_ASSIGN = 'leads.assign';
    public const LEADS_CONVERT = 'leads.convert';

    public const CUSTOM_FIELDS_MANAGE = 'custom_fields.manage';

    // Phase 6 — Sales module
    public const DEALS_VIEW = 'deals.view';
    public const DEALS_CREATE = 'deals.create';
    public const DEALS_EDIT = 'deals.edit';
    public const DEALS_DELETE = 'deals.delete';
    public const DEALS_CHANGE_STAGE = 'deals.change_stage';

    public const PIPELINES_MANAGE = 'pipelines.manage';

    public const TASKS_VIEW = 'tasks.view';
    public const TASKS_CREATE = 'tasks.create';
    public const TASKS_EDIT = 'tasks.edit';
    public const TASKS_DELETE = 'tasks.delete';
    public const TASKS_CHANGE_STATUS = 'tasks.change_status';
    public const TASKS_ARCHIVE = 'tasks.archive';

    public const FOLLOWUPS_VIEW = 'followups.view';
    public const FOLLOWUPS_CREATE = 'followups.create';
    public const FOLLOWUPS_EDIT = 'followups.edit';
    public const FOLLOWUPS_DELETE = 'followups.delete';
    public const FOLLOWUPS_ASSIGN = 'followups.assign';
    public const FOLLOWUPS_COMPLETE = 'followups.complete';
    public const FOLLOWUPS_CANCEL = 'followups.cancel';
    public const FOLLOWUPS_EXPORT = 'followups.export';
    public const FOLLOWUPS_MANAGE_RULES = 'followups.manage_rules';
    public const FOLLOWUPS_MANAGE_REMINDERS = 'followups.manage_reminders';

    // Phase 7 — Wallet module
    public const WALLET_VIEW = 'wallet.view';
    public const WALLET_TOPUP = 'wallet.topup';
    public const WALLET_TRANSFER = 'wallet.transfer';
    public const WALLET_WITHDRAW_REQUEST = 'wallet.withdraw_request';
    public const WALLET_TRANSACTIONS_VIEW = 'wallet.transactions.view';
    public const WALLET_ADJUST = 'wallet.adjust';
    public const WALLET_WITHDRAWALS_MANAGE = 'wallet.withdrawals.manage';
    public const WALLET_SETTINGS_MANAGE = 'wallet.settings.manage';

    // Phase 8 — Support module
    public const TICKETS_VIEW = 'tickets.view';
    public const TICKETS_CREATE = 'tickets.create';
    public const TICKETS_EDIT = 'tickets.edit';
    public const TICKETS_DELETE = 'tickets.delete';
    public const TICKETS_ASSIGN = 'tickets.assign';
    public const TICKET_CATEGORIES_MANAGE = 'ticket_categories.manage';

    // Phase 9 — Internal Mail module
    public const MAIL_VIEW = 'mail.view';
    public const MAIL_SEND = 'mail.send';
    public const MAIL_BROADCAST_TEAM = 'mail.broadcast_team';
    public const MAIL_BROADCAST_CUSTOMERS = 'mail.broadcast_customers';

    // Phase — Notification module (Bale + SMS)
    public const NOTIFICATIONS_CHARGE = 'notifications.charge';
    public const NOTIFICATIONS_VIEW_LOG = 'notifications.view_log';

    // Phase 10 — Knowledge module (KB articles + file library)
    public const KB_VIEW = 'kb.view';
    public const KB_MANAGE = 'kb.manage';
    public const FILES_VIEW = 'files.view';
    public const FILES_MANAGE = 'files.manage';
    public const FILES_MANAGE_UPLOADERS = 'files.manage_uploaders';

    // Client Records module (structured client history around a contact)
    public const CLIENT_RECORDS_VIEW = 'client_records.view';
    public const CLIENT_RECORDS_CREATE = 'client_records.create';
    public const CLIENT_RECORDS_EDIT = 'client_records.edit';
    public const CLIENT_RECORDS_DELETE = 'client_records.delete';
    public const CLIENT_RECORDS_VIEW_SENSITIVE = 'client_records.view_sensitive';
    public const CLIENT_RECORDS_MANAGE_SESSIONS = 'client_records.manage_sessions';
    public const CLIENT_RECORDS_MANAGE_MEDIA = 'client_records.manage_media';
    public const CLIENT_RECORDS_MANAGE_DOCUMENTS = 'client_records.manage_documents';
    public const CLIENT_RECORDS_EXPORT = 'client_records.export';

    // Appointments module (bookable resources, types, working hours)
    public const APPOINTMENTS_VIEW = 'appointments.view';
    public const APPOINTMENTS_CREATE = 'appointments.create';
    public const APPOINTMENTS_EDIT = 'appointments.edit';
    public const APPOINTMENTS_DELETE = 'appointments.delete';
    public const APPOINTMENTS_MANAGE_SETTINGS = 'appointments.manage_settings';

    /** @var array<string, string> permission key => module that registered it */
    private static array $moduleKeys = [];

    /** @return string[] every known permission key: Core's, then module-registered ones */
    public static function all(): array
    {
        return array_merge(self::coreKeys(), array_keys(self::$moduleKeys));
    }

    /**
     * Lets a module add its own keys from its register(), without Core
     * naming the module (docs/module-extension-points.md). Rules:
     * - a key must be "<module>.<action>" (lower-case, dot-separated) —
     *   a module can only register keys under its own prefix;
     * - Core keys and keys already registered by another module are
     *   never taken over (first registration wins);
     * - re-registering the same key for the same module is a no-op.
     * Invalid entries are skipped, never thrown: a module mistake must
     * not take the whole application down at boot.
     *
     * Registering a key does not grant it to anyone, and holding it has
     * no effect unless the module is also available to the user
     * (ModuleMiddleware / ModuleGate check that first).
     *
     * @param string[] $keys
     * @return string[] the keys actually registered for this module
     */
    public static function registerModuleKeys(string $module, array $keys): array
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $module) !== 1) {
            return [];
        }

        $core = self::coreKeys();
        $registered = [];

        foreach ($keys as $key) {
            if (!is_string($key)
                || preg_match('/^' . preg_quote($module, '/') . '(\.[a-z][a-z0-9_]*)+$/', $key) !== 1
                || in_array($key, $core, true)
                || (isset(self::$moduleKeys[$key]) && self::$moduleKeys[$key] !== $module)) {
                continue;
            }

            self::$moduleKeys[$key] = $module;
            $registered[] = $key;
        }

        return $registered;
    }

    /** @return string[] keys registered by modules (optionally only one module's) */
    public static function moduleKeys(?string $module = null): array
    {
        return array_keys(array_filter(self::$moduleKeys, fn (string $owner) => $module === null || $owner === $module));
    }

    /** Test helper: drops one module's registered keys. */
    public static function forgetModuleKeys(string $module): void
    {
        self::$moduleKeys = array_filter(self::$moduleKeys, fn (string $owner) => $owner !== $module);
    }

    /** @return string[] the code-defined Core catalogue (unchanged list) */
    private static function coreKeys(): array
    {
        return [
            self::ORGANIZATION_VIEW, self::ORGANIZATION_EDIT,
            self::USERS_VIEW, self::USERS_CREATE, self::USERS_EDIT, self::USERS_DELETE,
            self::USERS_ACTIVATE, self::USERS_DEACTIVATE,
            self::DEPARTMENTS_VIEW, self::DEPARTMENTS_CREATE, self::DEPARTMENTS_EDIT, self::DEPARTMENTS_DELETE,
            self::TEAMS_VIEW, self::TEAMS_CREATE, self::TEAMS_EDIT, self::TEAMS_DELETE,
            self::ROLES_VIEW, self::ROLES_CREATE, self::ROLES_EDIT, self::ROLES_DELETE, self::ROLES_ASSIGN,
            self::MODULES_MANAGE,
            self::CONTACTS_VIEW, self::CONTACTS_CREATE, self::CONTACTS_EDIT, self::CONTACTS_DELETE, self::CONTACTS_EXPORT, self::CONTACTS_IMPORT, self::CONTACTS_MANAGE_CATEGORIES,
            self::FINANCE_SETTINGS_VIEW, self::FINANCE_SETTINGS_EDIT,
            self::PRODUCTS_VIEW, self::PRODUCTS_CREATE, self::PRODUCTS_EDIT, self::PRODUCTS_DELETE,
            self::QUOTES_VIEW, self::QUOTES_CREATE, self::QUOTES_EDIT, self::QUOTES_DELETE, self::QUOTES_CONVERT,
            self::INVOICES_VIEW, self::INVOICES_CREATE, self::INVOICES_EDIT, self::INVOICES_DELETE, self::INVOICES_ISSUE, self::INVOICES_CANCEL,
            self::PAYMENTS_VIEW, self::PAYMENTS_CREATE, self::PAYMENTS_REFUND,
            self::CREDIT_NOTES_VIEW, self::CREDIT_NOTES_CREATE,
            self::TAX_VIEW, self::TAX_MANAGE,
            self::FINANCIAL_REPORTS_VIEW, self::FINANCIAL_PERIODS_VIEW, self::FINANCIAL_PERIODS_CLOSE, self::FINANCIAL_PERIODS_REOPEN,
            self::COMPANIES_VIEW, self::COMPANIES_CREATE, self::COMPANIES_EDIT, self::COMPANIES_DELETE, self::COMPANIES_MANAGE_CATEGORIES,
            self::LEADS_VIEW, self::LEADS_CREATE, self::LEADS_EDIT, self::LEADS_DELETE, self::LEADS_ASSIGN, self::LEADS_CONVERT,
            self::CUSTOM_FIELDS_MANAGE,
            self::DEALS_VIEW, self::DEALS_CREATE, self::DEALS_EDIT, self::DEALS_DELETE, self::DEALS_CHANGE_STAGE,
            self::PIPELINES_MANAGE,
            self::TASKS_VIEW, self::TASKS_CREATE, self::TASKS_EDIT, self::TASKS_DELETE,
            self::TASKS_CHANGE_STATUS, self::TASKS_ARCHIVE,
            self::FOLLOWUPS_VIEW, self::FOLLOWUPS_CREATE, self::FOLLOWUPS_EDIT, self::FOLLOWUPS_DELETE,
            self::FOLLOWUPS_ASSIGN, self::FOLLOWUPS_COMPLETE, self::FOLLOWUPS_CANCEL, self::FOLLOWUPS_EXPORT,
            self::FOLLOWUPS_MANAGE_RULES, self::FOLLOWUPS_MANAGE_REMINDERS,
            self::WALLET_VIEW, self::WALLET_TOPUP, self::WALLET_TRANSFER, self::WALLET_WITHDRAW_REQUEST,
            self::WALLET_TRANSACTIONS_VIEW, self::WALLET_ADJUST, self::WALLET_WITHDRAWALS_MANAGE, self::WALLET_SETTINGS_MANAGE,
            self::TICKETS_VIEW, self::TICKETS_CREATE, self::TICKETS_EDIT, self::TICKETS_DELETE, self::TICKETS_ASSIGN, self::TICKET_CATEGORIES_MANAGE,
            self::MAIL_VIEW, self::MAIL_SEND, self::MAIL_BROADCAST_TEAM, self::MAIL_BROADCAST_CUSTOMERS,
            self::NOTIFICATIONS_CHARGE, self::NOTIFICATIONS_VIEW_LOG,
            self::KB_VIEW, self::KB_MANAGE, self::FILES_VIEW, self::FILES_MANAGE, self::FILES_MANAGE_UPLOADERS,
            self::CLIENT_RECORDS_VIEW, self::CLIENT_RECORDS_CREATE, self::CLIENT_RECORDS_EDIT, self::CLIENT_RECORDS_DELETE,
            self::CLIENT_RECORDS_VIEW_SENSITIVE, self::CLIENT_RECORDS_MANAGE_SESSIONS, self::CLIENT_RECORDS_MANAGE_MEDIA,
            self::CLIENT_RECORDS_MANAGE_DOCUMENTS, self::CLIENT_RECORDS_EXPORT,
            self::APPOINTMENTS_VIEW, self::APPOINTMENTS_CREATE, self::APPOINTMENTS_EDIT,
            self::APPOINTMENTS_DELETE, self::APPOINTMENTS_MANAGE_SETTINGS,
        ];
    }

    public static function exists(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
