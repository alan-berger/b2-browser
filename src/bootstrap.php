<?php
declare(strict_types=1);

/**
 * Class autoloader for the B2Browser namespace. No Composer is needed at
 * runtime; composer.json exists only for the development test suite.
 */
if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('b2-browser requires PHP 8.1 or newer.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'B2Browser\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
