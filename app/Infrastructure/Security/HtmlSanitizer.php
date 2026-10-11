<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

/**
 * Allow-list HTML cleaner for rich content written in the app (help
 * articles). Keeps structural/formatting tags and a few safe
 * attributes; drops scripts, styles, frames, forms, event handlers,
 * inline style and any non-http(s)/mailto/relative URL. Unknown tags
 * are unwrapped (their text is kept). Defense in depth: the author is
 * trusted, but the output is shown to every user.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'h2', 'h3', 'h4', 'h5', 'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small',
        'sub', 'sup', 'code', 'pre', 'kbd', 'blockquote', 'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'a', 'img', 'span', 'div',
        'section', 'figure', 'figcaption', 'details', 'summary',
    ];

    /** Elements removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
        'button', 'select', 'textarea', 'option', 'link', 'meta', 'base', 'svg', 'math', 'template', 'noscript',
    ];

    private const GLOBAL_ATTRIBUTES = ['class', 'dir', 'lang', 'title', 'id'];

    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target'],
        'img' => ['src', 'alt', 'width', 'height'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'],
        'ol' => ['start'],
        'details' => ['open'],
    ];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        if (!class_exists(\DOMDocument::class)) {
            return self::fallback($html);
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8"><div id="__help_root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($doc->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('id') === '__help_root') {
                $root = $div;
                break;
            }
        }
        if ($root === null) {
            return self::fallback($html);
        }

        self::cleanChildren($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    }

    private static function cleanChildren(\DOMNode $node): void
    {
        // Copy first: the loop mutates the live child list.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            self::cleanChildren($child);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);

            $keep = in_array($name, $allowed, true);
            if ($keep && ($name === 'href' || $name === 'src')) {
                $keep = self::safeUrl($value, $name === 'href');
            }
            if ($keep && $name === 'target') {
                $keep = $value === '_blank';
            }

            if (!$keep) {
                $el->removeAttribute($attr->name);
            }
        }

        if ($tag === 'a' && $el->getAttribute('target') === '_blank') {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function safeUrl(string $url, bool $allowMailto): bool
    {
        // Strip control/whitespace chars browsers ignore inside a scheme ("java\nscript:").
        $compact = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $url));
        if ($compact === '' || str_starts_with($compact, '#') || str_starts_with($compact, '?')) {
            return true;
        }
        if (str_starts_with($compact, '//')) {
            return false;
        }
        if (str_starts_with($compact, '/')) {
            return true;
        }
        if (preg_match('#^https?://#', $compact) === 1) {
            return true;
        }
        if ($allowMailto && str_starts_with($compact, 'mailto:')) {
            return true;
        }

        // Relative path without a scheme ("other-page", "img/x.png").
        return preg_match('#^[a-z][a-z0-9+.\-]*:#', $compact) !== 1;
    }

    /** No DOM extension: tag allow-list only, every attribute removed. */
    private static function fallback(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|iframe|object|embed|svg|template)\b.*?</\1\s*>#is', '', $html);
        $allowed = '<' . implode('><', self::ALLOWED_TAGS) . '>';
        $html = strip_tags($html, $allowed);

        return (string) preg_replace('#<([a-z0-9]+)\b[^>]*?(/?)>#i', '<$1$2>', $html);
    }
}
