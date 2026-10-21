<?php

declare(strict_types=1);

/**
 * VENDOR tool — issues licenses for the Windows offline edition. Keep the private
 * key on YOUR computer only; never put it in a customer package or in git.
 *
 *   php license-tool.php keygen <folder>
 *       new key pair: <folder>/private-key.pem (keep) and public-key.pem
 *       (copy it over windows-offline/license/public-key.pem; licenses signed
 *       with an older key stop working).
 *
 *   php license-tool.php make <private-key.pem> <install-code> "<customer>" <max-users> [days]
 *       writes license-<install-code>.json; days omitted or 0 = no expiry.
 */

require __DIR__ . '/license/OfflineLicense.php';

$cmd = $argv[1] ?? '';

if ($cmd === 'keygen') {
    $dir = rtrim((string) ($argv[2] ?? '.'), '/\\');
    @mkdir($dir, 0700, true);
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    file_put_contents($dir . '/private-key.pem', $private);
    file_put_contents($dir . '/public-key.pem', openssl_pkey_get_details($key)['key']);
    echo "Written: {$dir}/private-key.pem (KEEP SECRET) and {$dir}/public-key.pem\n";
    exit(0);
}

if ($cmd === 'make') {
    [, , $keyFile, $code, $customer, $maxUsers] = $argv + [null, null, '', '', '', '0'];
    $days = (int) ($argv[6] ?? 0);
    $code = strtoupper(trim((string) $code));
    if (!is_file((string) $keyFile) || !preg_match('/^MCRM-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code) || trim((string) $customer) === '' || (int) $maxUsers < 1) {
        fwrite(STDERR, "Usage: php license-tool.php make <private-key.pem> <MCRM-XXXX-XXXX-XXXX> \"<customer>\" <max-users> [days]\n");
        exit(2);
    }
    $license = [
        'v' => 1,
        'product' => MirsysOfflineLicense::PRODUCT,
        'installCode' => $code,
        'licensedTo' => trim((string) $customer),
        'issuedAt' => date('Y-m-d'),
        'expiresAt' => $days > 0 ? date('Y-m-d', strtotime("+{$days} days")) : '',
        'maxUsers' => (int) $maxUsers,
    ];
    if (!openssl_sign(MirsysOfflineLicense::payload($license), $sig, (string) file_get_contents($keyFile), OPENSSL_ALGO_SHA256)) {
        fwrite(STDERR, "Could not sign with {$keyFile}\n");
        exit(1);
    }
    $license['sig'] = base64_encode($sig);
    $out = "license-{$code}.json";
    file_put_contents($out, json_encode($license, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    echo "Written: {$out} — {$license['licensedTo']}, {$license['maxUsers']} users, expires: " . ($license['expiresAt'] ?: 'never') . "\n";
    exit(0);
}

fwrite(STDERR, "Usage: php license-tool.php keygen <folder> | make <private-key.pem> <install-code> \"<customer>\" <max-users> [days]\n");
exit(2);
