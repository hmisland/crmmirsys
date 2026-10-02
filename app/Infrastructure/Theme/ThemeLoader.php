<?php

declare(strict_types=1);

namespace App\Infrastructure\Theme;

use App\Infrastructure\Config\Config;

/**
 * Per Phase 0 §27: presentation lives entirely under /themes/<name>/,
 * with a theme.json manifest. This class knows nothing about business
 * logic or the database — it only resolves paths, so swapping the
 * active theme is a config change, never a code change.
 */
final class ThemeLoader
{
    private readonly string $themesPath;
    private readonly string $activeTheme;

    public function __construct(string $themesPath, ?string $activeTheme = null)
    {
        $this->themesPath = rtrim($themesPath, '/');
        $this->activeTheme = $activeTheme ?? (string) Config::get('app.theme', 'default');
    }

    public function templatesPath(): string
    {
        return "{$this->themesPath}/{$this->activeTheme}/templates";
    }

    public function assetsPath(): string
    {
        return "{$this->themesPath}/{$this->activeTheme}/assets";
    }

    public function manifest(): array
    {
        $file = "{$this->themesPath}/{$this->activeTheme}/theme.json";

        if (!is_file($file)) {
            return [];
        }

        return json_decode((string) file_get_contents($file), true) ?: [];
    }

    /**
     * Public asset URL. Assumes /themes is exposed (or proxied) under
     * /assets/themes — see public/.htaccess.
     */
    public function assetUrl(string $relativePath): string
    {
        return '/assets/themes/' . $this->activeTheme . '/' . ltrim($relativePath, '/');
    }
}
