<?php

declare(strict_types=1);

namespace App\Application\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Infrastructure\Theme\ThemeLoader;

/**
 * /themes sits outside public/ (per Phase 0 §27, presentation is kept
 * separate from the webroot). On real shared hosting a deploy step can
 * instead copy or symlink theme assets into public/assets/themes for
 * the webserver to serve directly (faster, no PHP involved) — this
 * controller is the zero-configuration fallback that works even
 * without SSH/deploy tooling, which is why it exists in Phase 1.
 */
final class ThemeAssetController
{
    private const MIME_TYPES = [
        'css' => 'text/css',
        'js' => 'application/javascript',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg' => 'image/svg+xml',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

    public function __construct(private readonly ThemeLoader $theme)
    {
    }

    public function __invoke(Request $request): Response
    {
        $themeName = (string) $request->attribute('theme');
        $relativePath = (string) $request->attribute('path');

        // Reject path traversal outright — never trust the URL segment.
        if (str_contains($relativePath, '..') || str_contains($themeName, '..')) {
            return Response::html('Not found.', 404);
        }

        $themesRoot = dirname($this->theme->templatesPath(), 2); // .../themes
        $file = "{$themesRoot}/{$themeName}/assets/{$relativePath}";
        $realThemesRoot = realpath($themesRoot);
        $realFile = realpath($file);

        if ($realFile === false || $realThemesRoot === false || !str_starts_with($realFile, $realThemesRoot)) {
            return Response::html('Not found.', 404);
        }

        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mime = self::MIME_TYPES[$ext] ?? 'application/octet-stream';

        return Response::html((string) file_get_contents($realFile), 200)
            ->withHeader('Content-Type', $mime)
            ->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
