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
- `help_link('slug')` (`use function …\help_link;`) prints a "?" icon that
  opens help-center guide `/help/{slug}` in the shared `#help-dialog`
  popup. Put it right after a page title (`<h1>…<?= help_link('x') ?></h1>`)
  or a field label. Guides are written by the Super Admin at `/help`;
  their HTML may use `.help-callout` (`--tip`, `--warning`),
  `.help-example`, `.help-flow` (step chips) and `.table-wrap`.
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
- **Client-side tabs**: wrap `.tabs` (`button.tab-btn[data-tab="x"]`) and
  the panels (`.tab-panel[data-panel="x"]`, all but the first `hidden`)
  in one `[data-tabs]` element; `app.js` switches them and opens the tab
  holding a `.field-error` first. For page-level tabs (a tab bar card
  followed by cards) use `.tab-group` as the wrapper and
  `.tab-panel--stack` on each panel. `.page-stack` spaces several cards
  inside one wrapper (e.g. a form spanning cards).
- **Lists (required for every record list)**: action buttons in `.actions`,
  then the filter form `form.inline-form-row.filter-bar` (search + the
  list's own filters + "Filter" button + "Clear filters" link when
  `$pagination->filterQuery() !== []`), then
  `<?php include …/partials/pagination.php; ?>`, then the table. The
  controller passes `$pagination`
  (`App\Infrastructure\Pagination\Pagination`): `fromRequest($request,
  $total)` + repository `LIMIT/OFFSET` for big tables, or
  `Pagination::slice($rows, $request)` for lists already loaded whole.
  25 rows by default (25/50/100 via `?per_page=`); page links keep the
  active filters. `select[data-autosubmit]` submits its form on change.
- **On-demand dialog**: `button.card.kpi[data-related-url][data-related-title]`
  inside `.kpi-grid` (`.kpi-grid--compact` for small tiles) opens
  `dialog#related-dialog.modal.modal--wide` (`.modal__head` +
  `.modal__content[data-related-body]`) and loads the URL's HTML fragment
  into it — used for the contact page's related records.
- **Dense tables**: `.cell-clip` (single line with ellipsis),
  `.cell-note` (minimum width for a notes column), `.table--map`
  (tables whose cells hold full-width selects).
- **Record profile** (first used by Client Records, reusable by any
  record page): `.profile-head` (avatar `.avatar--lg` + title + sub-line +
  actions), `.fact-row` / `.fact` (compact label/value strip),
  `.record-layout` (main + aside, stacks on tablet), `.card--tabs`
  (server-side tab bar: `<a class="tab-btn" aria-current="page">`),
  `.event-list` + `.event__icon--{brand|success|info|warning|danger}`
  (icon timeline), `.course-list`/`.course` with `progress.progress`
  (native `<progress>`, no inline width), `.media-grid`/`.media-card`,
  `.locked` (permission placeholder), `.form-grid` (multi-column
  label/control groups inside `.card--form`), `.prose-text` (read-only
  multi-line user text).
- **Appointments calendar**: `.appt-toolbar` (view switch + date nav),
  `.appt-grid` (day table) with `.appt-slot--open|busy|closed|booked`
  cells and `.appt-slot__add` links, `.appt-chip` / `.appt-chip--{status}`
  (one appointment as a link card), `.appt-week` / `.appt-day` (week
  columns), `.appt-legend`, `.hours-table` / `.hours-range` (weekly
  working-hours editor).
- Dates/times/phones inside RTL text: wrap the value in
  `<span class="ltr">` (inline) — putting `.ltr` on a block element also
  flips its alignment.

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
