#!/usr/bin/env php
<?php
/**
 * Generate a TOTP secret and print what is needed to enrol it, entirely on
 * the server — no web page ever displays the secret.
 *
 *   php bin/totp-setup.php <username> [issuer]
 *   php bin/totp-setup.php --check <base32-secret>   # print the current code
 *
 * Show the QR code in the terminal with qrencode, if installed:
 *   qrencode -t ansiutf8 '<otpauth URI printed below>'
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use B2Browser\Totp;

if (($argv[1] ?? '') === '--check') {
    $key = Totp::decodeSecret($argv[2] ?? '');
    if ($key === null) {
        fwrite(STDERR, "Invalid Base32 secret.\n");
        exit(1);
    }
    echo Totp::codeAt($key, intdiv(time(), Totp::PERIOD)), PHP_EOL;
    exit(0);
}

$user = $argv[1] ?? '';
if ($user === '') {
    fwrite(STDERR, "Usage: php bin/totp-setup.php <username> [issuer]\n");
    exit(1);
}
$issuer = $argv[2] ?? 'B2 File Browser';
$secret = Totp::generateSecret();
$uri = Totp::provisioningUri($secret, $user, $issuer);

echo "Add to config.php:\n\n    'totp_secret' => '{$secret}',\n\n";
echo "Secret (manual entry): ", trim(chunk_split($secret, 4, ' ')), "\n";
echo "Provisioning URI:      {$uri}\n\n";
echo "Terminal QR code:      qrencode -t ansiutf8 '{$uri}'\n";
echo "Verify enrolment:      php bin/totp-setup.php --check {$secret}\n";
