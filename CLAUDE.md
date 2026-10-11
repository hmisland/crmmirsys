# CRM GROUP — project rules for AI-assisted development

Plain PHP 8.1 + MySQL, no framework, no Composer/Node at runtime (shared
cPanel hosting). Templates are PHP files under `themes/default/templates`.

## Before any UI work
Read `docs/ui-design-system.md` first and follow it. Do not invent new
colors, spacing, or one-off page styles — use the tokens and components
in `themes/default/assets/css/app.css`.

## UI rules (must follow)
- Every button/link action = icon + text: `<?= icon('plus') ?><?= e(t('...')) ?>`.
  Icons come only from `themes/default/templates/partials/icons.php`;
  add a `<symbol>` there if one is missing. Never paste raw SVG.
- Delete/destructive submit buttons: `class="btn btn--danger"` (or
  `link-button link-button--danger`) plus `data-confirm`.
- Status values: `<?= badge(t('module.status.' . $s), $s) ?>`.
- Templates that use `icon()`/`badge()` must import them:
  `use function App\Infrastructure\Theme\icon;` / `...\badge;`
- Forms: put label/control pairs inside `.card--form`; show errors with
  `<div class="field-error">` right after the control. Dates always use
  `<input type="text" class="crm-datepicker">` (see `docs/conventions.md`).
- Lists: `.table-responsive > table.table`; empty state is one
  `<td colspan="N" class="muted">` row.
- No inline `style=""`, no hardcoded colors, avoid `!important`.
- RTL/LTR: logical CSS only (`margin-inline-start`, `inset-inline-end`,
  `text-align: start`), never left/right. Must work in fa, ar (RTL) and
  en, tr, fr (LTR).
- New sidebar items go in the `$navGroups` model at the top of
  `themes/default/templates/layout.php`, behind the same module-access
  flag the route uses.

## Text / translations
- Never hardcode UI text; always `e(t('key'))`.
- Every new key must be added to all five files: `lang/en.php`,
  `lang/fa.php`, `lang/ar.php`, `lang/tr.php`, `lang/fr.php` (en is the
  fallback).

## Safety
- Do not change backend logic, APIs, database schema/migrations,
  authentication, RBAC/permissions, or financial calculations unless the
  task explicitly requires it. Hiding a menu item is not access control.
- The project is not a git repository: back up files before large edits.
- Never upload `.env` or the `ddddd/` backup folder anywhere.
