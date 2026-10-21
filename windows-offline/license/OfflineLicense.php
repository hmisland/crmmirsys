<?php

declare(strict_types=1);

/**
 * License for the Windows offline / office edition (windows-offline/router.php).
 *
 * - Install code: derived from this computer's Windows MachineGuid and a random
 *   id kept in runtime/license/install-id, so a copied folder on another computer
 *   gets a different code and the license no longer matches.
 * - License file (JSON) issued by the vendor with license-tool.php and the PRIVATE
 *   key (kept by the vendor only), verified here with public-key.pem:
 *   installCode, licensedTo, issuedAt, expiresAt ("" = no expiry), maxUsers.
 * - maxUsers counts users with status "active"; the router refuses to register,
 *   add or reactivate a user once the limit is reached.
 */
final class MirsysOfflineLicense
{
    public const PRODUCT = 'mirsys-crm';

    private string $dir;

    public function __construct(private readonly string $root)
    {
        $this->dir = $root . '/runtime/license';
    }

    public function installCode(): string
    {
        $idFile = $this->dir . '/install-id';
        $id = is_file($idFile) ? trim((string) file_get_contents($idFile)) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            $id = bin2hex(random_bytes(16));
            @mkdir($this->dir, 0775, true);
            @file_put_contents($idFile, $id . "\n");
        }
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $hash = hash('sha256', self::machineId() . '|' . $id, true);
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[ord($hash[$i]) % 32];
        }

        return 'MCRM-' . implode('-', str_split($code, 4));
    }

    public static function machineId(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $out = (string) @shell_exec('reg query HKLM\SOFTWARE\Microsoft\Cryptography /v MachineGuid 2>nul');
            if (preg_match('/MachineGuid\s+REG_SZ\s+([0-9a-fA-F-]{20,})/', $out, $m)) {
                return strtolower($m[1]);
            }
        }
        $etc = @file_get_contents('/etc/machine-id');

        return $etc !== false && trim($etc) !== '' ? trim($etc) : php_uname('n');
    }

    public static function payload(array $l): string
    {
        return implode('|', [
            (int) ($l['v'] ?? 1), (string) ($l['product'] ?? ''), (string) ($l['installCode'] ?? ''),
            (string) ($l['licensedTo'] ?? ''), (string) ($l['issuedAt'] ?? ''), (string) ($l['expiresAt'] ?? ''),
            (int) ($l['maxUsers'] ?? 0),
        ]);
    }

    /** @return array{ok: bool, reason: string, license: ?array} */
    public function verify(string $raw): array
    {
        $l = json_decode(trim($raw), true);
        if (!is_array($l) || !is_string($l['sig'] ?? null) || ($l['product'] ?? '') !== self::PRODUCT) {
            return ['ok' => false, 'reason' => 'فایل لایسنس معتبر نیست.', 'license' => null];
        }
        $key = @file_get_contents(__DIR__ . '/public-key.pem');
        $sig = base64_decode($l['sig'], true);
        if ($key === false || $sig === false || openssl_verify(self::payload($l), $sig, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return ['ok' => false, 'reason' => 'امضای فایل لایسنس معتبر نیست.', 'license' => null];
        }
        if ($l['installCode'] !== $this->installCode()) {
            return ['ok' => false, 'reason' => 'این لایسنس برای کامپیوتر دیگری صادر شده است (کد نصب: ' . $l['installCode'] . ').', 'license' => $l];
        }
        if (($l['expiresAt'] ?? '') !== '' && date('Y-m-d') > $l['expiresAt']) {
            return ['ok' => false, 'reason' => 'اعتبار لایسنس در تاریخ ' . $l['expiresAt'] . ' تمام شده است.', 'license' => $l];
        }
        if ((int) ($l['maxUsers'] ?? 0) < 1) {
            return ['ok' => false, 'reason' => 'تعداد کاربر در لایسنس معتبر نیست.', 'license' => $l];
        }

        return ['ok' => true, 'reason' => '', 'license' => $l];
    }

    /** @return array{ok: bool, reason: string, license: ?array} the stored license's state */
    public function status(): array
    {
        $file = $this->dir . '/license.json';
        if (!is_file($file)) {
            return ['ok' => false, 'reason' => 'هنوز لایسنسی نصب نشده است.', 'license' => null];
        }

        return $this->verify((string) file_get_contents($file));
    }

    /** @return array{ok: bool, reason: string, license: ?array} */
    public function install(string $raw): array
    {
        $r = $this->verify($raw);
        if ($r['ok']) {
            @mkdir($this->dir, 0775, true);
            file_put_contents($this->dir . '/license.json', trim($raw) . "\n");
        }

        return $r;
    }

    /** POST routes that add an active user: registration, "add user", reactivation. */
    public static function addsUser(string $method, string $path): bool
    {
        return $method === 'POST' && (
            $path === '/register'
            || preg_match('#^/api(/v\d+)?/register$#', $path) === 1
            || $path === '/org/users'
            || preg_match('#^/org/users/\d+/activate$#', $path) === 1
        );
    }

    /** Active users, read with the CRM's own .env database settings; null when the database can't be read. */
    public function activeUsers(): ?int
    {
        $env = [];
        foreach (@file($this->root . '/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^\s*([A-Z_]+)\s*=\s*"?(.*?)"?\s*$/', $line, $m)) {
                $env[$m[1]] = $m[2];
            }
        }
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? '127.0.0.1', $env['DB_PORT'] ?? '3306', $env['DB_DATABASE'] ?? 'crm'),
                $env['DB_USERNAME'] ?? 'root',
                $env['DB_PASSWORD'] ?? '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
        } catch (Throwable) {
            return null;
        }
    }
}
