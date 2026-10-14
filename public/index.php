<?php
/**
 * b2-browser entry point — the only PHP file that belongs in the web root.
 *
 * The application directory (src/, views/) and config.php should live
 * OUTSIDE the document root. By default they are expected one level up:
 *
 *   /home/you/b2-browser/            ← application (not web-accessible)
 *   ├── config.php
 *   ├── src/  views/  bin/
 *   └── public/                      ← document root, or copy its contents
 *       ├── index.php                   into your existing web root
 *       └── assets/
 *
 * Override either location with environment variables set by the web server
 * (Apache: SetEnv, nginx: fastcgi_param) or by editing the two lines below.
 */
declare(strict_types=1);

$appRoot = getenv('B2B_APP_ROOT') ?: dirname(__DIR__);
$configFile = getenv('B2B_CONFIG') ?: $appRoot . '/config.php';

require $appRoot . '/src/bootstrap.php';

(new B2Browser\App($appRoot, $configFile))->run();
