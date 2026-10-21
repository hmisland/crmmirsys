<?php

declare(strict_types=1);

/**
 * Front gate of the Windows offline edition (start.bat: php -S ... windows-offline\router.php).
 * Static files pass straight through; /__license shows and installs the license;
 * without a valid license every page is the license page; once the licensed number
 * of active users is reached, registering / adding / reactivating a user is refused.
 * Everything else is the CRM's own public/index.php, unchanged.
 */

$root = dirname(__DIR__);
require __DIR__ . '/license/OfflineLicense.php';

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$static = realpath($root . '/public' . $path);
if ($path !== '/' && $static !== false && is_file($static) && str_starts_with($static, realpath($root . '/public')) && !str_ends_with($static, '.php')) {
    return false;
}

$license = new MirsysOfflineLicense($root);

$page = function (int $code, string $title, string $body) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . htmlspecialchars($title) . '</title><style>'
        . 'body{font-family:Tahoma,Vazirmatn,sans-serif;background:#f5f6f8;color:#1f2937;margin:0;padding:24px}'
        . '.box{max-width:640px;margin:40px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:24px}'
        . 'h1{font-size:20px;margin:0 0 12px}.code{direction:ltr;font:600 20px monospace;background:#f3f4f6;padding:10px 14px;border-radius:8px;display:inline-block}'
        . '.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;padding:10px 12px;border-radius:8px}.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;padding:10px 12px;border-radius:8px}'
        . 'textarea{width:100%;min-height:120px;direction:ltr;font-family:monospace;box-sizing:border-box}button,a.btn{background:#2563eb;color:#fff;border:0;border-radius:8px;padding:10px 16px;cursor:pointer;text-decoration:none;display:inline-block}'
        . 'dl{display:grid;grid-template-columns:auto 1fr;gap:6px 12px}dt{color:#6b7280}</style></head><body><div class="box">'
        . $body . '</div></body></html>';
};

$licensePage = function (array $status, string $notice = '') use ($license, $page) {
    $l = $status['license'];
    $body = '<h1>لایسنس Mirsys CRM</h1>';
    if ($notice !== '') {
        $body .= $notice;
    } elseif (!$status['ok']) {
        $body .= '<p class="err">' . htmlspecialchars($status['reason']) . '</p>';
    }
    if ($status['ok'] && $l !== null) {
        $body .= '<p class="ok">لایسنس معتبر است.</p><dl>'
            . '<dt>به نام</dt><dd>' . htmlspecialchars((string) $l['licensedTo']) . '</dd>'
            . '<dt>تعداد کاربر</dt><dd>' . (int) $l['maxUsers'] . ' (فعال: ' . htmlspecialchars((string) ($license->activeUsers() ?? '?')) . ')</dd>'
            . '<dt>اعتبار تا</dt><dd>' . htmlspecialchars($l['expiresAt'] !== '' ? (string) $l['expiresAt'] : 'نامحدود') . '</dd></dl>'
            . '<p><a class="btn" href="/">ورود به CRM</a></p>';
    }
    $body .= '<p>کد نصب این کامپیوتر:</p><p class="code">' . htmlspecialchars($license->installCode()) . '</p>'
        . '<p>این کد را برای فروشنده بفرستید تا فایل لایسنس را برایتان صادر کند. سپس فایل را این‌جا بارگذاری کنید:</p>'
        . '<form method="post" action="/__license" enctype="multipart/form-data">'
        . '<p><input type="file" name="license_file" accept=".json,.lic,.txt"></p>'
        . '<p>یا متن فایل لایسنس را این‌جا بچسبانید:</p><p><textarea name="license_text"></textarea></p>'
        . '<p><button type="submit">نصب لایسنس</button></p></form>';
    $page($status['ok'] ? 200 : 403, 'لایسنس', $body);
};

if ($path === '/__license') {
    if ($method === 'POST') {
        $raw = '';
        if (!empty($_FILES['license_file']['tmp_name']) && is_uploaded_file($_FILES['license_file']['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES['license_file']['tmp_name']);
        }
        if (trim($raw) === '') {
            $raw = (string) ($_POST['license_text'] ?? '');
        }
        $r = $license->install($raw);
        $licensePage($r['ok'] ? $r : $license->status(), $r['ok'] ? '<p class="ok">لایسنس نصب شد.</p>' : '<p class="err">' . htmlspecialchars($r['reason']) . '</p>');

        return true;
    }
    $licensePage($license->status());

    return true;
}

$status = $license->status();
if (!$status['ok']) {
    $licensePage($status);

    return true;
}

if (MirsysOfflineLicense::addsUser($method, $path)) {
    $active = $license->activeUsers();
    $max = (int) $status['license']['maxUsers'];
    if ($active !== null && $active >= $max) {
        $page(403, 'سقف کاربران', '<h1>به سقف کاربران لایسنس رسیده‌اید</h1>'
            . '<p class="err">این لایسنس برای ' . $max . ' کاربر فعال است و الان ' . $active . ' کاربر فعال وجود دارد.</p>'
            . '<p>برای افزودن کاربر جدید، یک کاربر را غیرفعال کنید یا لایسنس با تعداد کاربر بیشتر بگیرید.</p>'
            . '<p><a class="btn" href="/">بازگشت</a> <a class="btn" href="/__license">وضعیت لایسنس</a></p>');

        return true;
    }
}

chdir($root . '/public');
require $root . '/public/index.php';

return true;
