<?php

declare(strict_types=1);

namespace App\Infrastructure\Theme;

/**
 * Per Phase 0 §27: templates never call htmlspecialchars() ad hoc and
 * never build translation strings by hand. e() is the only sanctioned
 * way to output a dynamic value in a template; t() is the sanctioned
 * way to output translatable text.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Real translation-key resolution (Phase 11) via
 * App\Infrastructure\Localization\Locale/Translator — the Phase 1
 * stopgap's own docblock said only this function's body would need
 * to change once Phase 11 landed, and that's exactly what happened;
 * no template rewrite was needed for the templates that already
 * called t('some.key'). Locale::get() can be null only in contexts
 * outside the normal request lifecycle (a CLI script, a unit test
 * that never called Application::handle()) — the key itself is
 * echoed unchanged in that case, same as the old stopgap behavior,
 * rather than fatal-erroring.
 */
function t(string $key, array $replace = []): string
{
    // app.name is deployment branding from config, not translated
    // text — it stays a config lookup rather than living in every
    // language file (a brand name isn't something to "translate").
    if ($key === 'app.name') {
        return (string) \App\Infrastructure\Config\Config::get('app.name', $key);
    }

    $translator = \App\Infrastructure\Localization\Locale::get();

    if ($translator === null) {
        $value = $key;
        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
    }

    return $translator->get($key, $replace);
}

/**
 * Presentation-only helper: references one symbol from the theme's
 * inline SVG sprite (themes/<name>/templates/partials/icons.php, which
 * the layout prints once per page). One icon set, one stroke weight,
 * one sizing rule — templates never paste raw SVG. Icons are
 * decorative (aria-hidden) by design: the visible text label next to
 * an icon, or an aria-label on an icon-only control, is what assistive
 * technology reads. Directional icons (arrows/chevrons) get the
 * `icon--dir` class so the theme mirrors them in RTL locales.
 */
function icon(string $name, string $class = ''): string
{
    static $directional = ['chevron-right', 'chevron-left', 'arrow-right', 'arrow-left', 'log-in', 'log-out', 'reply', 'forward', 'send', 'undo'];

    $classes = trim('icon ' . (in_array($name, $directional, true) ? 'icon--dir ' : '') . $class);

    return '<svg class="' . e($classes) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"></use></svg>';
}

/**
 * Presentation-only helper: renders an already-translated status label
 * as a colored pill. The raw status value only picks a color tone —
 * one shared vocabulary across modules, so "paid", "won" and
 * "resolved" all read as success everywhere. Unknown values fall back
 * to neutral rather than guessing.
 */
function badge(string $label, ?string $status): string
{
    static $tones = [
        'success' => ['active', 'won', 'paid', 'accepted', 'converted', 'completed', 'resolved', 'approved', 'delivered', 'enabled'],
        'warning' => ['pending', 'partial', 'in_progress', 'contacted', 'unpaid', 'expired', 'no_show', 'requested'],
        'danger' => ['lost', 'rejected', 'cancelled', 'failed', 'disabled', 'unqualified', 'deprecated'],
        'info' => ['issued', 'open', 'new', 'qualified', 'sent', 'scheduled', 'planned', 'confirmed'],
    ];

    $tone = 'neutral';
    foreach ($tones as $candidate => $values) {
        if (in_array((string) $status, $values, true)) {
            $tone = $candidate;
            break;
        }
    }

    return '<span class="badge' . ($tone !== 'neutral' ? ' badge--' . $tone : '') . '">' . e($label) . '</span>';
}

/**
 * Presentation-only helper: a small "?" button that opens a help-center
 * article (/help/{slug}) in the shared help popup, or as a normal page
 * when JavaScript is off. Use next to a page title or field:
 * <?= help_link('deal-contract-sales-project') ?>
 */
function help_link(string $slug, string $class = ''): string
{
    $url = '/help/' . rawurlencode($slug);

    return '<a href="' . e($url) . '" class="' . e(trim('help-link ' . $class)) . '"'
        . ' data-related-url="' . e($url . '?fragment=1') . '" data-related-dialog="help-dialog"'
        . ' data-related-title="' . e(t('help.title')) . '"'
        . ' aria-label="' . e(t('help.open')) . '" title="' . e(t('help.open')) . '">'
        . icon('help-circle') . '</a>';
}

/**
 * Stage 5.2 (L2): a money amount for display under the currency precision
 * policy (App\Infrastructure\Finance\CurrencyPrecision): IRR with up to 3
 * significant decimals, other currencies with their configured precision,
 * rounded half-up. Pass the template's LocaleFormatter to keep its localized
 * digits; without one: Latin digits with "," grouping, as number_format()
 * gave. Display only — stored amounts never change. Never adds currencies.
 */
function money(mixed $amount, ?string $currencyCode, ?\App\Infrastructure\Localization\LocaleFormatter $fmt = null): string
{
    $amount = trim((string) $amount) === '' ? '0' : trim((string) $amount);
    $currency = $currencyCode !== null && $currencyCode !== '' ? \App\Infrastructure\Finance\CurrencyPrecision::currencyByCode($currencyCode) : null;

    return ($fmt ?? new \App\Infrastructure\Localization\LocaleFormatter('en'))
        ->number($amount, \App\Infrastructure\Finance\CurrencyPrecision::displayDecimals($amount, $currency));
}

/**
 * A stored system instant (created_at, updated_at, sent_at … — UTC) in the
 * current organization's time zone, as "Y-m-d H:i:s" (same shape as the
 * stored value, so substr() / LocaleFormatter::date() keep working). null
 * or empty stays null. Never use it on values users typed as local times
 * (due dates, appointment times, DATE columns) — those are already local.
 */
function local_time(?string $stored): ?string
{
    return \App\Infrastructure\Time\OrganizationTimezone::shared()->toLocal($stored)?->format('Y-m-d H:i:s');
}

/**
 * The current organization's wall clock now ("Y-m-d H:i:s") — compare it
 * with values users typed as local times (due dates, session times).
 */
function local_now(): string
{
    return \App\Infrastructure\Time\OrganizationTimezone::shared()->localNow();
}

/** The current organization's date today (Y-m-d) — e.g. the default of a date field. */
function local_today(): string
{
    return \App\Infrastructure\Time\OrganizationTimezone::shared()->today();
}
