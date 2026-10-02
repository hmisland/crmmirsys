# UI design system (default theme)

Presentation-only conventions for `themes/default`. Nothing here touches
controllers, services, RBAC or data — templates still only receive
view-model data and call `e()` / `t()` (plus the two helpers below).

## Files

| File | Role |
|---|---|
| `themes/default/assets/css/app.css` | Tokens + every component. Section 1 (tokens) is the only place raw colors/sizes live. |
| `themes/default/templates/layout.php` | App shell: sidebar (module → section), topbar (breadcrumb, search, language, account menu), flash alerts, confirm dialog. Guest pages get a centered auth shell. |
| `themes/default/templates/partials/icons.php` | The one SVG icon sprite (Lucide geometry, 24px grid, 2px stroke). |
| `themes/default/assets/js/app.js` | Drawer, collapsible sidebar, dropdowns, dismissible alerts, delete confirmation. Progressive enhancement only. |
| `themes/default/templates/errors/_page.php` | Standalone, dependency-free error document used by 404/500. |

## Template helpers (`app/Infrastructure/Theme/functions.php`)

```php
use function App\Infrastructure\Theme\icon;
use function App\Infrastructure\Theme\badge;

<?= icon('plus') ?>                       // decorative <svg>, aria-hidden
<?= badge(t('invoices.status.' . $s), $s) ?> // colored status pill
```

- `icon()` names = `<symbol id="i-NAME">` in the sprite. Add a symbol there
  rather than pasting SVG into a template. Arrows/chevrons mirror in RTL
  automatically.
- `badge()` picks a tone from the raw status value (paid/won/resolved →
  success, pending/partial → warning, cancelled/rejected → danger,
  issued/open/new → info, anything else → neutral).

## Components

- **Buttons**: `.btn` (secondary), `.btn--primary`, `.btn--danger`,
  `.btn--ghost`, `.btn--sm`, `.btn--icon` (icon-only — must have
  `aria-label`). Text-style: `.link-button`, `.link-button--danger`.
  Always icon + text, icon first: `<?= icon('save') ?><?= e(t('common.save')) ?>`.
- **Destructive actions**: add `data-confirm` to the submit button
  (optionally with a custom message). `app.js` shows the confirm dialog;
  the form, its action and payload are untouched.
- **Forms**: inside `.card--form` (or a bare `<form>` directly in a
  `.card`) a label/control sequence is laid out automatically. Field
  width follows data type: numbers, dates, codes → short; email, phone,
  password, selects → medium; text → long; textarea → full. Override with
  `.field-sm` / `.field-md` / `.field-lg`. A `.field-error` right after a
  control turns that control red; `required` adds the `*` to its label.
- **Toolbars / filters**: `.inline-form-row` (wraps; stacks on phones).
- **Tables**: `.table-responsive > .table`. Empty-state rows
  (`<td colspan class="muted">`) get an icon automatically. `.num` for
  right-aligned numeric cells.
- **Other**: `.alert--success|error|warning|info`, `.badge--*`, `.tabs` /
  `.tab-btn`, `.dropdown` (`<details>`), `[data-tooltip]`,
  `dialog.modal`, `.pagination`, `.breadcrumb`, `.empty-state`,
  `.skeleton`, `.spinner`, `.kpi`, `.settings-tile`, `.page-head`,
  `.actions` (button row).

## RTL / LTR rules

- Use logical properties only (`margin-inline-start`, `inset-inline-end`,
  `text-align: start`). The only physical values in `app.css` are
  explicitly mirrored under `[dir="rtl"]`.
- Never assume label length — buttons and nav items size to content and
  wrap; the sidebar truncates with an ellipsis and a `title` tooltip.
- Email/phone/number inputs are always LTR; user-entered text blocks use
  `unicode-bidi: plaintext` so mixed-script content punctuates correctly.

## Navigation

The sidebar model lives at the top of `layout.php`: groups → items, each
with the URL prefixes that count as "inside" it (drives highlight +
breadcrumb). Visibility uses the same module-access flags as before; it
is a convenience only — `ModuleMiddleware`/RBAC still enforce access.
