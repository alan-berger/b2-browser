#!/usr/bin/env php
<?php
/**
 * Prompt for a password (twice, without echo) and print a password_hash()
 * string for auth.password_hash in config.php.
 *
 *   php bin/hash-password.php
 *   printf '%s' "$PASSWORD" | php bin/hash-password.php --stdin
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function prompt(string $label): string
{
    fwrite(STDERR, $label);
    $tty = stream_isatty(STDIN);
    if ($tty) {
        shell_exec('stty -echo');
    }
    $line = fgets(STDIN);
    if ($tty) {
        shell_exec('stty echo');
        fwrite(STDERR, PHP_EOL);
    }
    return rtrim((string) $line, "\r\n");
}

if (in_array('--stdin', $argv, true)) {
    $password = (string) stream_get_contents(STDIN);
} else {
    $password = prompt('Password: ');
    if (prompt('Confirm:  ') !== $password) {
        fwrite(STDERR, "Passwords do not match.\n");
        exit(1);
    }
}

if (strlen($password) < 12) {
    fwrite(STDERR, "Refusing: use at least 12 characters (a passphrase is better).\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
