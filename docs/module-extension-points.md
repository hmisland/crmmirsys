# Module extension points

Phase 5 · Step 1. Three small Core mechanisms let a module plug in
**without Core, or any other module, naming it**:

1. `ProvidesServices`: expose services.
2. `RecordTypeRegistry`: declare record types that notes, tags and attachments (and later follow-ups) can attach to.
3. `RelatedPanelRegistry`: add a panel to another record's page.

They add no access rules of their own. See [Module access](#module-access).

All three are bound in `App\Core\Application::registerCoreBindings()`. Modules use them from their own `register(Container $container)`, which Application calls for every installed module (`bootstrapModuleRoutes()`).

---

## 1. ProvidesServices

`App\Infrastructure\Modules\ProvidesServices` is an optional interface on a module's bootstrap class:

```php
public function services(): array; // [ServiceClassOrInterface::class => $instance|null]
```

Right after `register()`, Application passes the module to `ModuleServiceRegistrar::registerModule()`, which binds each entry into the container. The rules are deterministic:

| Case | Result |
|---|---|
| Module doesn't implement the interface | Nothing happens; existing modules need no change |
| Value is `null` | Skipped (the module chose not to expose it) |
| Key is not a class or interface, or the value is not an instance of it | Skipped and logged |
| Abstract already bound (Core, or an earlier module) | Skipped and logged. **First registration wins**; a module can never replace a Core service. Discovery order is alphabetical by module folder |

The old per-module `instanceof` blocks in `bootstrapModuleRoutes()` are untouched and keep working. New modules should not add more of them.

**A bound service is not an entitlement.** Services are bound for every *installed* module, as before. A consumer must still check that the module is available and the permission is held. Use `ModuleGate::allows()`, or the `Modules\ClientRecords\Services\ClientIntegrations` pattern, before calling `$container->make(...)`.

## 2. Record type registry

`App\Infrastructure\Records\RecordTypeRegistry` holds `RecordType`s:

| Field | Meaning |
|---|---|
| `key` | Value stored in polymorphic columns (`notable_type`, `subject_type`, `related_type`, …); `[a-z][a-z0-9_]*` |
| `module` | Owning module key; `null` = Core |
| `viewPermission` | Permission needed to view a record of this type |
| `labelKey` | Translation key for the type's name |
| `showPath` | Record page, e.g. `/contacts/{id}` |
| `icon` | Optional icon name |

Core registers `contact`, `company` and `lead`.

API:

- `register()`: returns `false` on a duplicate key; first wins, logged.
- `has()`, `get()`, `all()`
- `isAvailable($key, $user)`: registered and the owning module is available to the user.
- `canView($key, $user)`: also requires `viewPermission`.
- `viewableBy($user)`

**Looking up another module's record (step 2.2).** A `RecordType` may carry an optional `finder`: `Closure(int $id): ?array`, backed by the owning module's tenant-scoped repository. Another module then calls `find($key, $id, $user)` instead of importing the owner. `find()` returns the row only when all of these hold:

- the type has a finder;
- `canView()` passes;
- the row belongs to the user's organization (checked again here);
- the user may view **this row**, i.e. the permission's own/team/department scope applied through `ModuleGate::allows(..., $record)`.

In every other case it returns `null`, and the caller treats that exactly like "the other module isn't there". Types registered without a finder behave as before.

**`recordSupport` (step 2.7).** This optional flag defaults to `true`. It controls whether Core's `/records/{type}/{id}/notes|tags|attachments` routes accept the type. A module that registers a type only so others can link to it passes `false`, so those routes keep returning 404 for it. Sales' `deal` does this, because deals have their own notes.

**Reacting to another module's event without importing it (step 2.7).** Subscribe with the event's class name as a string:

```php
$container->make(EventBus::class)->listen('Modules\\Sales\\Events\\DealStageChanged', $listener);
```

The listener then:

- reads the event's public properties, checking them with `property_exists`;
- logs and ignores an event of an unexpected shape;
- scopes its work to the event's organization (`OrganizationContext::runAs`);
- acts only when its own module is active and entitled for that organization.

A test in the listening module must assert that the string still names the real class.

Used by `RecordSupportController` (`/records/{type}/{id}/notes|tags|attachments`). `{type}` must be a type the user `canView()`, otherwise 404. The redirect back goes to the type's `showPath`.

Record-level rules (own/team scope on one row) stay the owning module's job. The registry answers only "may this user use this *kind* of record".

## 3. Related panel registry

`App\Infrastructure\Records\RelatedPanelRegistry` holds `RelatedPanel`s:

| Field | Meaning |
|---|---|
| `subjectType` | Host page's record type (`contact`, `company`, `lead`, `deal`, …) |
| `id` | Unique per subject, e.g. `widgets.deal` |
| `module` | Owning module; `null` = Core |
| `titleKey` | Translation key for the heading |
| `render` | `Closure(array $subject, array $user): string`; returns HTML |
| `order` | Lower first; ties sorted by `id` |
| `permission` | Optional permission needed to see the panel |
| `icon` | Optional icon name |

Host pages include the slot and name only their own subject type:

```php
<?php $panelSubjectType = 'deal'; $panelSubject = $deal; include $theme->templatesPath() . '/partials/related-panels.php'; ?>
```

`TemplateEngine` passes the registry to every template as `$relatedPanels`. The slot is already on the contact (Details tab), company, lead and deal pages. It renders nothing until a module registers a panel.

`render()` behaviour:

- Shows only panels whose module is available and whose permission is held.
- Skips panels that return empty HTML.
- Logs and skips a panel whose callback throws, so a broken add-on never breaks the host page.

The panel's module must escape its own output and filter its own rows (e.g. own scope). The host page has already authorized the subject itself.

This is the boundary that keeps modules apart. A future "Contracts" panel on the deal page is registered by Contracts against the subject type `deal`. Sales never imports Contracts, and Contracts never imports Sales.

## Module access

Everything goes through `App\Infrastructure\Modules\ModuleGate::allows($user, $moduleKey, $permission)`. It is a thin wrapper:

```
module part     → ModuleAccessResolver::resolvedForUser()
                  installed → globally active → organization entitled → user override
permission part → AuthorizationEngine::can($user, $permission)
```

- `null` module = Core (always on). `null` permission = no extra permission. A guest is never allowed.
- Module results are memoised per module and user for one request. `Application::handle()` calls `reset()` at the start of each request.
- This is the same chain `ModuleMiddleware` and `RbacMiddleware` enforce on routes. There is no second licensing or access system.
- Super Admin behaves exactly as in `ModuleAccessResolver` (all modules on). Cross-organization data access still requires `SuperAdminContext::run()`.
- Disabling a module for an organization or user hides its record types and panels on the next request. No data is deleted.

## Example (fake module "widgets", not a real module)

```php
namespace Modules\Widgets;

use App\Core\Container;
use App\Infrastructure\Modules\ProvidesServices;
use App\Infrastructure\Records\RecordType;
use App\Infrastructure\Records\RecordTypeRegistry;
use App\Infrastructure\Records\RelatedPanel;
use App\Infrastructure\Records\RelatedPanelRegistry;
use App\Infrastructure\Theme\TemplateEngine;

final class WidgetsModule implements \App\Infrastructure\Modules\ModuleInterface, ProvidesServices
{
    private ?Services\WidgetService $widgets = null;

    public function register(Container $container): void
    {
        $this->widgets = new Services\WidgetService(/* ... */);

        $container->make(RecordTypeRegistry::class)->register(new RecordType(
            'widget', 'widgets', 'widgets.view', 'widgets.record', '/widgets/{id}', 'box'
        ));

        $widgets = $this->widgets;
        $templates = $container->make(TemplateEngine::class);
        $container->make(RelatedPanelRegistry::class)->register(new RelatedPanel(
            subjectType: 'deal',
            id: 'widgets.deal',
            module: 'widgets',
            titleKey: 'widgets.panel_title',
            render: fn (array $deal, array $user) => $templates->render('widgets/_deal-panel', [
                'rows' => $widgets->forDeal($user, (int) $deal['id']), // service applies own/team scope
            ]),
            order: 50,
            permission: 'widgets.view',
        ));
    }

    public function services(): array
    {
        return [Services\WidgetService::class => $this->widgets];
    }

    // manifest(), routes() (behind ModuleMiddleware('widgets') + RbacMiddleware), migrationsPath() as usual
}
```

## 4. Module permission keys (step 2.3)

A module registers its own keys from `register()`:

```php
Permission::registerModuleKeys('widgets', ['widgets.view', 'widgets.edit']);
```

After that, `Permission::all()` and `Permission::exists()` include them. The role editor (`/org/roles`) lists them, and saving a role accepts them.

Registration rules:

| Key | Result |
|---|---|
| Not `<module>.<action>` (lower-case, dot-separated) | Skipped |
| Outside the module's own prefix | Skipped |
| A Core key | Skipped; never taken over |
| Already registered by another module | Skipped; first wins |
| Already registered by the same module | No-op |

Invalid entries are skipped, never thrown, and the method returns the keys it actually registered. The existing Core constants and list are unchanged; they now come from a private `coreKeys()`.

Registering a key grants nothing. Default grants come from the module's **own** migration, see `modules/Contracts/Migrations/0003_grant_contracts_permissions.php`. Holding a key has no effect while the module isn't available to the user, because module access is checked first.

What a module still adds the conventional way:

- A sidebar entry in `layout.php`'s `$navGroups`.
- Translations in the five `lang/*.php` files.

## Tests

`php tests/lite/run.php` runs the plain-PHP runner, since PHPUnit is not installed. Pass an optional filter to run a subset, e.g. `php tests/lite/run.php Record`.

- `ProvidesServicesTest`, `RecordTypeRegistryTest`, `RelatedPanelRegistryTest` need no database.
- `BackwardCompatTest`:
  - boots the real app against `crm_local` only (it refuses any other database or a non-local `APP_ENV`);
  - runs inside one transaction that is rolled back;
  - renders the contact, company, lead and deal pages;
  - exercises notes, tags, attachments and timeline.
