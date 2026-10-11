<?php

declare(strict_types=1);

namespace App\Infrastructure\Theme;

/**
 * Deliberately simple: PHP itself is the template language (no new
 * syntax to learn, no compile step needed on shared hosting), scoped
 * through include so templates cannot see the engine's internals —
 * only the variables explicitly passed to render().
 *
 * Per Phase 0 §27, templates must not: touch the database, contain
 * business logic, or hard-code translation strings. Nothing in this
 * class prevents that by itself — it's enforced by code review — but
 * only `e()`/`t()` (see functions.php) and passed-in view-model data
 * are available inside a template, which keeps the easy path the
 * correct one.
 */
final class TemplateEngine
{
    /**
     * $relatedPanels is handed to every template (like $theme) so
     * partials/related-panels.php can render module-contributed panels
     * on a record page; optional, so existing constructions still work.
     */
    public function __construct(
        private readonly ThemeLoader $theme,
        private readonly ?\App\Infrastructure\Records\RelatedPanelRegistry $relatedPanels = null,
    ) {
        require_once __DIR__ . '/functions.php';
    }

    public function render(string $view, array $data = []): string
    {
        $file = $this->theme->templatesPath() . '/' . $view . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$view}");
        }

        $data['theme'] = $this->theme;
        $data['relatedPanels'] = $this->relatedPanels;

        return $this->includeIsolated($file, $data);
    }

    /**
     * Renders $view, then injects the result into $layout at the
     * $layout template's `$content` variable. Kept as a second method
     * (rather than a directive inside templates) to keep the "PHP is
     * the template language" story simple and explicit.
     */
    public function renderWithLayout(string $view, string $layout, array $data = []): string
    {
        $content = $this->render($view, $data);

        return $this->render($layout, array_merge($data, ['content' => $content]));
    }

    private function includeIsolated(string $__file, array $__data): string
    {
        extract($__data, EXTR_SKIP);
        ob_start();
        include $__file;

        return (string) ob_get_clean();
    }
}
