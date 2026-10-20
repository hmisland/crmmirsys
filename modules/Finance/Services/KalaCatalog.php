<?php

declare(strict_types=1);

namespace Modules\Finance\Services;

/**
 * Read-only access to the national product/service list (kala.db): the
 * same SQLite file the tax project builds (scripts/_import_kala.py there) —
 * table kala(id TEXT 13 digits, norm TEXT, vat INTEGER, taxable TEXT,
 * type TEXT) plus the FTS5 index kala_fts over norm.
 *
 * Optional: when the file is missing, still being built ("<file>.building"),
 * or PHP lacks pdo_sqlite / FTS5, available() is false and callers keep the
 * feature switched off. Never writes to the file.
 */
final class KalaCatalog
{
    private ?\PDO $pdo = null;
    private ?bool $available = null;

    public function __construct(private string $path)
    {
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $this->available = false;
        if (!extension_loaded('pdo_sqlite') || !is_file($this->path) || is_file($this->path . '.building')) {
            return false;
        }
        try {
            $pdo = new \PDO('sqlite:' . $this->path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
            ]);
            // Both the table and the FTS5 index must answer.
            $pdo->query('SELECT id FROM kala LIMIT 1')->fetch();
            $pdo->query("SELECT rowid FROM kala_fts WHERE kala_fts MATCH '\"a\"*' LIMIT 1")->fetch();
            $this->pdo = $pdo;
            $this->available = true;
        } catch (\Throwable) {
            $this->pdo = null;
        }

        return $this->available;
    }

    /** One entry by its 13-digit ID, or null. @return array{id: string, title: string, vat: ?int, taxable: ?string, type: ?string}|null */
    public function find(string $code): ?array
    {
        $code = self::code($code);
        if ($code === null || !$this->available()) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, norm, vat, taxable, type FROM kala WHERE id = ? LIMIT 1');
        $stmt->execute([$code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row ? self::row($row) : null;
    }

    /**
     * Prefix search on every word (FTS5, fast); a 13-digit query looks the ID up.
     *
     * @return list<array{id: string, title: string, vat: ?int, taxable: ?string, type: ?string}>
     */
    public function search(string $query, int $limit = 30): array
    {
        if (!$this->available()) {
            return [];
        }
        $limit = max(1, min($limit, 50));
        $code = self::code($query);
        if ($code !== null) {
            $one = $this->find($code);

            return $one !== null ? [$one] : [];
        }
        $words = array_slice(array_values(array_filter(explode(' ', self::normalize($query)), fn (string $w): bool => mb_strlen($w) >= 2)), 0, 6);
        if ($words === []) {
            return [];
        }
        $match = implode(' AND ', array_map(fn (string $w): string => '"' . str_replace('"', '""', $w) . '"*', $words));
        try {
            $stmt = $this->pdo->prepare(
                'SELECT k.id, k.norm, k.vat, k.taxable, k.type FROM kala_fts f JOIN kala k ON k.rowid = f.rowid
                 WHERE kala_fts MATCH ? ORDER BY f.rank LIMIT ' . $limit
            );
            $stmt->execute([$match]);

            return array_map([self::class, 'row'], $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable) {
            return [];
        }
    }

    /** 1 exempt (معاف), 0 taxable (مشمول), null unknown — from the list's "taxable" text. */
    public static function exemptFlag(?string $taxable): ?int
    {
        $taxable = trim((string) $taxable);

        return match (true) {
            $taxable === '' => null,
            str_contains($taxable, 'معاف') => 1,
            str_contains($taxable, 'مشمول') => 0,
            default => null,
        };
    }

    /** The 13-digit ID (Persian / Arabic digits accepted), or null. */
    public static function code(string $raw): ?string
    {
        $digits = (string) preg_replace('/\s+/', '', strtr(trim($raw), self::DIGITS));

        return preg_match('/^\d{13}$/', $digits) === 1 ? $digits : null;
    }

    /** Same normalization as the tax project's index builder (must match it exactly). */
    public static function normalize(string $text): string
    {
        $text = strtr($text, self::LETTERS + self::DIGITS);
        $text = (string) preg_replace('/[\x{064B}-\x{0652}\x{0640}\x{200B}-\x{200F}\x{202A}-\x{202E}]/u', '', $text);

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    private const LETTERS = [
        'ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ئ' => 'ی', 'ۀ' => 'ه', 'ة' => 'ه',
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ٲ' => 'ا',
    ];

    private const DIGITS = [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ];

    private static function row(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'title' => (string) $row['norm'],
            'vat' => $row['vat'] !== null ? (int) $row['vat'] : null,
            'taxable' => $row['taxable'] !== null ? (string) $row['taxable'] : null,
            'type' => $row['type'] !== null ? (string) $row['type'] : null,
        ];
    }
}
