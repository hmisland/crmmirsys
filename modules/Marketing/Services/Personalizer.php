<?php

declare(strict_types=1);

namespace Modules\Marketing\Services;

/**
 * {{variable}} placeholders in campaign text. Only the names below are
 * accepted (an unknown one is a validation error, never sent as-is).
 * In HTML every value is escaped; in plain text/SMS it is inserted as is.
 */
final class Personalizer
{
    public const VARIABLES = ['first_name', 'last_name', 'full_name', 'company', 'email', 'phone', 'organization', 'unsubscribe_url'];

    private const PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/';

    /** @return string[] unknown variable names used in $texts */
    public static function unknownVariables(string ...$texts): array
    {
        $unknown = [];
        foreach ($texts as $text) {
            if (preg_match_all(self::PATTERN, $text, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    if (!in_array(strtolower($name), self::VARIABLES, true)) {
                        $unknown[$name] = $name;
                    }
                }
            }
        }

        return array_values($unknown);
    }

    /** @param array<string, string> $vars */
    public static function render(string $template, array $vars, bool $html = false): string
    {
        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($vars, $html): string {
            $value = (string) ($vars[strtolower($m[1])] ?? '');

            return $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }, $template);
    }

    public static function uses(string $text, string $variable): bool
    {
        return preg_match('/\{\{\s*' . preg_quote($variable, '/') . '\s*\}\}/i', $text) === 1;
    }

    /**
     * Variables for one recipient row (contact fields + snapshot).
     *
     * @return array<string, string>
     */
    public static function varsFor(array $recipient, string $organizationName, string $unsubscribeUrl = ''): array
    {
        $first = trim((string) ($recipient['first_name'] ?? ''));
        $last = trim((string) ($recipient['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);

        return [
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $full !== '' ? $full : trim((string) ($recipient['recipient_name'] ?? '')),
            'company' => trim((string) ($recipient['company_name'] ?? '')),
            'email' => trim((string) ($recipient['email'] ?? '')),
            'phone' => trim((string) ($recipient['phone'] ?? '')),
            'organization' => $organizationName,
            'unsubscribe_url' => $unsubscribeUrl,
        ];
    }
}
