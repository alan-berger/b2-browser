<?php
/**
 * End-to-end tests: start a mock B2 server and the real application under
 * PHP's built-in web server, then drive the app over HTTP.
 *
 *   php tests/e2e/run.php
 *
 * Exits non-zero if any check fails.
 */
declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use B2Browser\Totp;

$root = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/b2b-e2e-' . bin2hex(random_bytes(4));
mkdir("{$tmp}/storage", 0700, true);
$stateFile = "{$tmp}/mock-state.json";
$mockPort = free_port();
$appPort = free_port();
$mock = "http://127.0.0.1:{$mockPort}";
$app = "http://127.0.0.1:{$appPort}/";

$totpSecret = Totp::generateSecret();
$totpKey = (string) Totp::decodeSecret($totpSecret);
$password = 'correct horse battery staple';

write_config("{$tmp}/config.php", [
    'b2' => ['key_id' => 'test-key-id', 'application_key' => 'test-application-key', 'bucket_name' => 'cams',
        'bucket_id' => 'bucket123', 'prefix' => 'camera-footage', 'files_per_page' => 100, 'api_base' => $mock],
    'auth' => ['username' => 'alan', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'totp_secret' => $totpSecret],
    'session' => ['secure_cookie' => false],
    'storage_dir' => "{$tmp}/storage",
    'site' => ['homepage' => 'https://example.org/'],
    'log' => ['path' => "{$tmp}/activity.log"],
    'alerts' => ['ntfy' => ['enabled' => true, 'server' => $mock, 'topic' => 'cams']],
]);

$procs = [
    start_server($mockPort, "{$root}/tests/e2e/mock-b2.php", null, ['MOCK_STATE' => $stateFile]),
    start_server($appPort, null, "{$root}/public", ['B2B_CONFIG' => "{$tmp}/config.php", 'B2B_APP_ROOT' => $root]),
];
register_shutdown_function(static function () use ($procs, $tmp): void {
    foreach ($procs as $p) {
        proc_terminate($p);
    }
    exec('rm -rf ' . escapeshellarg($tmp));
});
wait_for($mock . '/health');
wait_for($app);

$failures = 0;
$checks = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    echo ($ok ? "  ✔ " : "  ✘ ") . $label . ($ok || $detail === '' ? '' : "\n      → {$detail}") . "\n";
    if (!$ok) {
        $failures++;
    }
}
function section(string $title): void
{
    echo "\n{$title}\n";
}
function mock_state(): array
{
    global $stateFile;
    return json_decode((string) file_get_contents($stateFile), true) ?: [];
}
function set_mock(array $changes): void
{
    global $stateFile;
    file_put_contents($stateFile, json_encode(array_replace(mock_state(), $changes)));
}
function csrf(string $html): string
{
    return preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) ? html_entity_decode($m[1]) : '';
}
function code(int $offset = 0): string
{
    global $totpKey;
    return Totp::codeAt($totpKey, intdiv(time(), 30) + $offset);
}
/** Full sign-in for a fresh client, using a code offset not yet consumed. */
function sign_in(Client $c, int $codeOffset): void
{
    global $password;
    $r = $c->get('');
    $r = $c->post('', ['action' => 'login', 'csrf_token' => csrf($r->body), 'username' => 'alan', 'password' => $password]);
    $r = $c->post('', ['action' => 'mfa', 'csrf_token' => csrf($r->body), 'code' => code($codeOffset)]);
    if ($r->status !== 303) {
        throw new RuntimeException("sign-in failed: {$r->status}");
    }
}

// ============================================================================
section('Unauthenticated access');
$a = new Client($app, "{$tmp}/jar-a");
$r = $a->get('');
check($r->status === 200 && str_contains($r->body, 'name="password"'), 'root shows the login form');
$csp = $r->header('content-security-policy');
check(str_contains($csp, "script-src 'self'") && !str_contains($csp, 'unsafe-inline'), 'CSP has no unsafe-inline', $csp);
check(!preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $r->body), 'no inline <script> blocks in the page');
foreach (['x-frame-options' => 'DENY', 'x-content-type-options' => 'nosniff', 'referrer-policy' => 'no-referrer', 'cache-control' => 'no-store'] as $h => $v) {
    check($r->header($h) === $v, "header {$h}: {$v}", $r->header($h));
}
check(str_contains($r->header('permissions-policy'), 'camera=()'), 'Permissions-Policy present');
check(str_contains($csp, "font-src 'self'"), 'CSP allows same-origin fonts only');
check(str_contains($r->body, '<script src="assets/theme-init.js"></script>') && !str_contains($r->body, 'class="b2-brand"'), 'unthemed page: theme init loaded, no brand header');
$cookie = $r->header('set-cookie');
check(stripos($cookie, 'httponly') !== false && stripos($cookie, 'samesite=strict') !== false, 'session cookie is HttpOnly + SameSite=Strict', $cookie);

$r = $a->get('?stream=' . rawurlencode('camera-footage/2026/10/14/000001/10-30-00.mp4'));
check($r->status === 200 && str_contains($r->body, 'name="password"') && !str_contains($r->body, 'MP4DATA'), '?stream= no longer serves anything (login form instead)');
$r = $a->get('?download=' . rawurlencode('camera-footage/2026/10/14/000001/10-30-00.mp4'));
check(!str_contains($r->body, 'MP4DATA'), 'download requires login');
$r = $a->getWithBasicAuth('?download=' . rawurlencode('camera-footage/2026/10/14/000001/10-30-00.mp4'), 'alan', $password);
check(!str_contains($r->body, 'MP4DATA'), 'HTTP Basic credentials are not accepted (MFA cannot be bypassed)');

section('CSRF');
$r = $a->post('', ['action' => 'login', 'username' => 'alan', 'password' => $password]);
check($r->status === 400, 'login without CSRF token is rejected (400)', (string) $r->status);

section('Brute-force protection');
for ($i = 1; $i <= 4; $i++) {
    $r = $a->post('', ['action' => 'login', 'csrf_token' => csrf($a->get('')->body), 'username' => 'alan', 'password' => "wrong{$i}"]);
}
check($r->status === 401 && str_contains($r->body, 'Invalid username or password'), '4th wrong password → 401 with generic message');
$r = $a->post('', ['action' => 'login', 'csrf_token' => csrf($a->get('')->body), 'username' => 'nobody', 'password' => 'x']);
check($r->status === 429 && $r->header('retry-after') !== '', '5th failure → 429 locked with Retry-After', (string) $r->status);
$b = new Client($app, "{$tmp}/jar-b");
$r = $b->get('');
check($r->status === 429, 'lock applies to a new session from the same address');
$r = $b->post('', ['action' => 'login', 'csrf_token' => csrf($r->body), 'username' => 'alan', 'password' => $password]);
check($r->status === 429, 'correct password is refused while locked');
$state = mock_state();
$alerts = array_column($state['ntfy'], 'title');
check(count(array_keys($alerts, 'Address locked out')) === 1, 'lockout alert sent exactly once', json_encode($alerts));
check(count(array_keys($alerts, 'Repeated failed logins')) === 1, 'failed-login alert sent once (at 3 failures)', json_encode($alerts));
$log = (string) file_get_contents("{$tmp}/activity.log");
check(substr_count($log, '"ADDRESS_LOCKED"') === 1, 'ADDRESS_LOCKED logged once');
unlink("{$tmp}/storage/ratelimit.json"); // simulate the lock expiring

$guesses = 12;
$logBefore = substr_count((string) file_get_contents("{$tmp}/activity.log"), '"LOGIN_FAILED"');
$batch = [];
for ($i = 0; $i < $guesses; $i++) {
    $client = new Client($app, "{$tmp}/jar-par-{$i}");
    $batch[] = [$client, ['action' => 'login', 'csrf_token' => csrf($client->get('')->body), 'username' => 'alan', 'password' => "parallel{$i}"]];
}
$statuses = parallel_posts($app, $batch);
$verified = substr_count((string) file_get_contents("{$tmp}/activity.log"), '"LOGIN_FAILED"') - $logBefore;
check($verified <= 5, "{$guesses} parallel guesses → at most 5 verified (got {$verified})", implode(',', $statuses));
check(count(array_keys($statuses, 429)) >= $guesses - 5, 'the rest are refused as locked');
unlink("{$tmp}/storage/ratelimit.json");

$r = (new Client($app, "{$tmp}/jar-head"))->request('', null, [CURLOPT_NOBODY => true]);
check($r->status === 405, 'HEAD is refused (405), so it cannot pull objects from B2', (string) $r->status);

section('Login with MFA');
$c = new Client($app, "{$tmp}/jar-c");
$r = $c->get('?path=' . rawurlencode('camera-footage/2026/10/14/'));
$r = $c->post('', ['action' => 'login', 'csrf_token' => csrf($r->body), 'username' => 'alan', 'password' => $password]);
check($r->status === 200 && str_contains($r->body, 'name="code"'), 'correct password → TOTP form');
$r = $c->get('?path=camera-footage/');
check(str_contains($r->body, 'name="code"') && !str_contains($r->body, 'file-item'), 'files stay hidden until the second factor');
$r = $c->post('', ['action' => 'mfa', 'csrf_token' => csrf($r->body), 'code' => '000000' === code() ? '111111' : '000000']);
check($r->status === 401 && str_contains($r->body, 'Invalid or already-used code'), 'wrong TOTP code → 401');
$used = code();
$r = $c->post('', ['action' => 'mfa', 'csrf_token' => csrf($r->body), 'code' => $used]);
check($r->status === 303 && $r->header('location') === '?path=camera-footage%2F', 'valid code → 303 back to the last requested folder', $r->status . ' ' . $r->header('location'));
check(str_contains($r->header('set-cookie'), 'b2b_session='), 'session id rotated at login');

$d = new Client($app, "{$tmp}/jar-d");
$r = $d->post('', ['action' => 'login', 'csrf_token' => csrf($d->get('')->body), 'username' => 'alan', 'password' => $password]);
$r = $d->post('', ['action' => 'mfa', 'csrf_token' => csrf($r->body), 'code' => $used]);
check($r->status === 401, 'the same code is rejected in another session (replay)');
unlink("{$tmp}/storage/ratelimit.json");

section('Browsing and prefix confinement');
$r = $c->get('');
check($r->status === 200 && str_contains($r->body, '>2026<') && str_contains($r->body, '>bulk<'), 'root lists folders under the prefix');
check(!str_contains($r->body, 'other') && !str_contains($r->body, 'private'), 'nothing outside the prefix is listed');
foreach (['other/', 'camera-footage-private/', 'camera-footage/../other/', '/etc/'] as $p) {
    $r = $c->get('?path=' . rawurlencode($p));
    check($r->status === 400, "?path={$p} → 400", (string) $r->status);
}
$r = $c->get('?date=2026-10-14');
check($r->status === 303 && $r->header('location') === '?path=camera-footage%2F2026%2F10%2F14%2F', 'date jump redirects to the day folder', $r->header('location'));
$r = $c->get('?date=2026-02-30');
check($r->status === 400, 'invalid date → 400');

set_mock(['calls' => []]);
$r = $c->get('?path=' . rawurlencode('camera-footage/2026/10/14/000001/'));
check($r->status === 200, 'camera folder lists');
check(str_contains($r->body, '&lt;img src=x onerror=alert(1)&gt;.jpg') && !str_contains($r->body, '<img src=x'), 'object names are HTML-escaped');
check(!str_contains($r->body, 'hidden.mp4') && !str_contains($r->body, 'zz-partial.mp4'), 'hide markers and unfinished uploads are skipped');
check(substr_count($r->body, 'class="thumb"') === 3, 'image thumbnails rendered', (string) substr_count($r->body, 'class="thumb"'));
$auths = count(array_filter(mock_state()['calls'], static fn($c) => str_contains($c, 'b2_get_download_authorization')));
check($auths === 1, 'one download authorisation covers all thumbnails in the folder', (string) $auths);
check(str_contains($r->header('content-security-policy'), "img-src 'self' {$mock}"), 'CSP img-src allows only this account\'s download host', $r->header('content-security-policy'));
check(str_contains($r->body, 'Camera 000001') && str_contains($r->body, '2026-10-14'), 'date and camera metadata shown');
$thumb = preg_match('/<img src="([^"]+)" alt="" class="thumb"/', $r->body, $m) ? html_entity_decode($m[1]) : '';
$t = (new Client('', "{$tmp}/jar-x"))->get($thumb);
check($t->status === 200 && str_starts_with($t->body, "\xFF\xD8"), 'thumbnail URL works without a session');

section('Pagination');
$r = $c->get('?path=' . rawurlencode('camera-footage/bulk/'));
check(str_contains($r->body, 'file-000.mp4') && str_contains($r->body, 'Next →') && !str_contains($r->body, '← Previous'), 'page 1 has Next only');
$r = $c->get('?path=' . rawurlencode('camera-footage/bulk/') . '&page=2');
check(str_contains($r->body, 'file-100.mp4') && !str_contains($r->body, 'file-099.mp4') && str_contains($r->body, '← Previous'), 'page 2 continues at item 101 with Previous');
$r = $c->get('?path=' . rawurlencode('camera-footage/bulk/') . '&page=3');
check(str_contains($r->body, 'file-249.mp4') && !str_contains($r->body, 'Next →'), 'page 3 is the last');
$r = $c->get('?path=' . rawurlencode('camera-footage/bulk/') . '&page=2');
check(str_contains($r->body, 'file-100.mp4'), 'Previous from page 3 returns to page 2');
$r = $c->get('?path=' . rawurlencode('camera-footage/bulk/') . '&page=9');
check(str_contains($r->body, 'Page 1 ') && str_contains($r->body, 'file-000.mp4'), 'unvisited page number falls back to page 1');

section('Playback');
$r = $c->get('?play=' . rawurlencode('camera-footage/2026/10/14/000001/10-45-00 #1?.mp4'));
check($r->status === 200 && preg_match('/<source src="([^"]+)"/', $r->body, $m) === 1, 'player page renders');
$src = html_entity_decode($m[1] ?? '');
check(str_contains($src, '/file/cams/camera-footage/2026/10/14/000001/10-45-00%20%231%3F.mp4?Authorization='), 'signed URL percent-encodes the object name', $src);
$v = (new Client('', "{$tmp}/jar-x"))->get($src);
check($v->status === 200 && $v->body === 'special-name-video', 'signed URL fetches the video');
check(str_contains($r->header('content-security-policy'), "media-src {$mock}"), 'CSP media-src limited to the download host');
$r = $c->get('?play=' . rawurlencode('other/secret.mp4'));
check($r->status === 400, 'playing outside the prefix → 400');

section('Downloads');
$r = $c->get('?download=' . rawurlencode('camera-footage/2026/10/14/000001/10-30-00.mp4'));
check($r->status === 200 && $r->body === 'MP4DATA-' . str_repeat('x', 5000), 'download streams the exact bytes');
check($r->header('content-type') === 'video/mp4' && $r->header('content-length') === '5008', 'type and length forwarded', $r->header('content-type') . ' ' . $r->header('content-length'));
check($r->header('content-disposition') === 'attachment; filename="10-30-00.mp4"; filename*=UTF-8\'\'10-30-00.mp4', 'RFC 6266 Content-Disposition', $r->header('content-disposition'));
$r = $c->get('?download=' . rawurlencode('camera-footage/2026/10/14/000001/10-45-00 #1?.mp4'));
check($r->body === 'special-name-video', 'names with space, # and ? download correctly');
$r = $c->get('?download=' . rawurlencode('camera-footage/2026/10/14/000001/empty.mp4'));
check($r->status === 200 && $r->body === '' && $r->header('content-disposition') !== '', 'zero-byte file downloads with headers');
$r = $c->get('?download=' . rawurlencode('camera-footage/2026/10/14/000001/missing.mp4'));
check($r->status === 404 && str_contains($r->body, 'That file does not exist') && !str_contains($r->body, 'not_found'), 'missing file → 404 page, not B2 JSON');
foreach (['other/secret.mp4', 'camera-footage-private/secret.mp4', 'camera-footage/../other/secret.mp4'] as $p) {
    $r = $c->get('?download=' . rawurlencode($p));
    check($r->status === 400 && !str_contains($r->body, 'secret'), "download of {$p} → 400");
}

section('Token expiry recovery');
$before = count(array_filter(mock_state()['calls'], static fn($x) => str_contains($x, 'authorize')));
set_mock(['expire_next' => true]);
$r = $c->get('?path=' . rawurlencode('camera-footage/2026/'));
$after = count(array_filter(mock_state()['calls'], static fn($x) => str_contains($x, 'authorize')));
check($r->status === 200 && $after === $before + 1, 'expired B2 token is refreshed once and the request succeeds');
set_mock(['expire_next' => true]);
$r = $c->get('?download=' . rawurlencode('camera-footage/2026/10/13/000001/09-00-00.mp4'));
check($r->status === 200 && $r->body === str_repeat('A', 1000), 'download also recovers from an expired token');

section('Logout');
$r = $c->get('?logout=1');
check($r->status === 200 && str_contains($r->body, 'file-item'), 'GET ?logout=1 does nothing (logout is POST + CSRF)');
$r = $c->post('', ['action' => 'logout']);
check($r->status === 400, 'logout without CSRF → 400');
$r = $c->post('', ['action' => 'logout', 'csrf_token' => csrf($c->get('')->body)]);
check($r->status === 200 && str_contains($r->body, 'Signed out'), 'POST logout with CSRF signs out');
$r = $c->get('');
check(str_contains($r->body, 'name="password"'), 'after logout the login form is shown again');

section('B2 key scope enforcement');
set_mock(['scope' => 'master']);
@unlink("{$tmp}/storage/b2-token.json");
$e = new Client($app, "{$tmp}/jar-e");
sign_in($e, 1);
$r = $e->get('');
check($r->status === 500 && str_contains($r->body, 'not restricted to a bucket') && !str_contains($r->body, 'file-item'), 'an unrestricted key is refused, with reasons shown to the owner');
check(str_contains($r->body, 'deleteFiles'), 'dangerous capabilities are named');
set_mock(['scope' => 'restricted']);
@unlink("{$tmp}/storage/b2-token.json");

section('MFA setup page');
$f = new Client($app, "{$tmp}/jar-f");
sign_in($f, -1);
$r = $f->get('?mfa_setup=1');
check($r->status === 404 && !str_contains($r->body, $totpSecret), 'disabled by default; the secret is never shown');

section('Password-only login and session timeouts');
$variantConfig = static function (string $name, array $overrides) use ($tmp, $mock, $password): string {
    $dir = "{$tmp}/{$name}";
    mkdir("{$dir}/storage", 0700, true);
    $base = [
        'b2' => ['key_id' => 'test-key-id', 'application_key' => 'test-application-key', 'bucket_name' => 'cams',
            'bucket_id' => 'bucket123', 'prefix' => 'camera-footage/', 'api_base' => $mock],
        'auth' => ['username' => 'alan', 'password_hash' => password_hash($password, PASSWORD_DEFAULT)],
        'session' => ['secure_cookie' => false],
        'storage_dir' => "{$dir}/storage",
        'log' => ['enabled' => false],
    ];
    write_config("{$dir}/config.php", array_replace_recursive($base, $overrides));
    return "{$dir}/config.php";
};
$startVariant = static function (string $configPath) use ($root, &$procs): string {
    $port = free_port();
    $procs[] = start_server($port, null, "{$root}/public", ['B2B_CONFIG' => $configPath, 'B2B_APP_ROOT' => $root]);
    $url = "http://127.0.0.1:{$port}/";
    wait_for($url);
    return $url;
};
$shortApp = $startVariant($variantConfig('short', ['session' => ['idle_timeout' => 2, 'absolute_timeout' => 5]]));
$g = new Client($shortApp, "{$tmp}/jar-g");
$r = $g->post('', ['action' => 'login', 'csrf_token' => csrf($g->get('')->body), 'username' => 'alan', 'password' => $password]);
check($r->status === 303, 'password-only login completes without a TOTP step');
check(str_contains($g->get('')->body, 'file-item'), 'listing visible after password-only login');
sleep(3);
$r = $g->get('');
check(str_contains($r->body, 'name="password"') && str_contains($r->body, 'Your session expired'), 'idle timeout ends the session');
$r = $g->post('', ['action' => 'login', 'csrf_token' => csrf($r->body), 'username' => 'alan', 'password' => $password]);
$stillIn = true;
for ($i = 0; $i < 4; $i++) {
    sleep(1);
    $stillIn = $stillIn && str_contains($g->get('')->body, 'file-item'); // activity keeps the idle timer alive
}
check($r->status === 303 && $stillIn, 'regular activity keeps the session alive past the idle timeout');
sleep(3);
$r = $g->get('');
check(str_contains($r->body, 'name="password"'), 'absolute timeout ends an active session');

section('Site theme');
$themedApp = $startVariant($variantConfig('themed', ['theme' => [
    'stylesheets' => ['/style.css', 'assets/theme.css'],
    'logo' => '/bimi/logo.svg', 'logo_alt' => 'Logo & "mark"', 'heading' => 'example.org <site>',
    'nav' => [['HOME', '/'], ['B2', '/b2/']], 'favicon' => '/favicon.ico', 'storage_key' => 'site-theme',
]]));
$r = (new Client($themedApp, "{$tmp}/jar-t"))->get('');
check(str_contains($r->body, '<link rel="stylesheet" href="/style.css">') && strpos($r->body, '/style.css') < strpos($r->body, 'assets/b2-browse.css'), 'site stylesheet linked before the app stylesheet');
check(str_contains($r->body, 'alt="Logo &amp; &quot;mark&quot;"') && str_contains($r->body, 'example.org &lt;site&gt;'), 'logo alt text and heading are escaped');
check(str_contains($r->body, '<a href="/b2/">B2</a>') && str_contains($r->body, 'data-theme-key="site-theme"'), 'navigation and shared theme key rendered');
$markupApp = $startVariant($variantConfig('markup', ['theme' => [
    'logo' => '/logo.svg', 'heading' => 'example.org', 'nav' => [['HOME', '/'], ['B2', '/b2/']],
    'classes' => ['wrapper' => 'blended_grid', 'header' => 'pageHeader', 'nav' => 'pageLeftMenu', 'toggle' => 'theme-toggle'],
]]));
$logoApp = $startVariant($variantConfig('loginlogo', ['theme' => ['login_logo' => 'assets/brand.svg', 'login_logo_alt' => 'Brand "B"']]));
$r = (new Client($logoApp, "{$tmp}/jar-l"))->get('');
check(str_contains($r->body, '<img src="assets/brand.svg" alt="Brand &quot;B&quot;" class="b2-login-logo">') && !str_contains($r->body, '🔒'), 'login logo replaces the padlock (alt text escaped)');
$r = (new Client($markupApp, "{$tmp}/jar-m"))->get('');
check(str_contains($r->body, '🔒'), 'padlock shown on the sign-in page by default');
check(str_contains($r->body, '<div class="blended_grid">') && str_contains($r->body, '<div class="pageHeader">')
    && str_contains($r->body, '<img src="/logo.svg" alt="" width="64" height="64"><h1>example.org</h1>')
    && str_contains($r->body, '<div class="pageLeftMenu">') && str_contains($r->body, 'class="theme-toggle" id="themeToggle"')
    && !str_contains($r->body, 'b2-brand'), 'site markup mode reproduces the host header and menu');
$badApp = $startVariant($variantConfig('badtheme', ['theme' => ['nav' => [['X', 'javascript:alert(1)']]]]));
$r = (new Client($badApp, "{$tmp}/jar-u"))->get('');
check($r->status === 500 && !str_contains($r->body, 'javascript:'), 'a javascript: nav link is a configuration error');

section('Configuration failures');
$openApp = $startVariant($variantConfig('open', ['auth' => ['username' => '', 'password_hash' => '', 'allow_no_auth' => true]]));
$r = (new Client($openApp, "{$tmp}/jar-h"))->get('');
check($r->status === 200 && str_contains($r->body, 'file-item') && !str_contains($r->body, 'Sign out'), 'allow_no_auth serves without login when explicitly enabled');
$closedApp = $startVariant($variantConfig('closed', ['auth' => ['username' => '', 'password_hash' => '']]));
$r = (new Client($closedApp, "{$tmp}/jar-i"))->get('');
check($r->status === 500 && !str_contains($r->body, 'file-item'), 'empty credentials fail closed (500, nothing served)');
check(!str_contains($r->body, 'password_hash'), 'config problems are not disclosed to visitors');
$brokenApp = $startVariant("{$tmp}/does-not-exist.php");
$r = (new Client($brokenApp, "{$tmp}/jar-j"))->get('');
check($r->status === 500 && str_contains($r->body, 'Configuration error'), 'missing config file → generic 500');

section('State files');
$perms = substr(sprintf('%o', fileperms("{$tmp}/storage/totp-used.json")), -3);
check($perms === '600', 'state files are created 0600', $perms);
check(!str_contains((string) file_get_contents("{$tmp}/activity.log"), $password), 'password never appears in the log');

section('PHP diagnostics');
$diagnostics = '';
foreach (glob("{$tmp}/server-*.log") ?: [] as $file) {
    $diagnostics .= (string) file_get_contents($file);
}
preg_match_all('/PHP (Warning|Notice|Deprecated|Fatal error|Parse error).*$/m', $diagnostics, $m);
check($m[0] === [], 'no PHP warnings, notices or deprecations in the server logs', implode("\n      ", array_slice($m[0], 0, 5)));

echo "\n{$checks} checks, {$failures} failed\n";
exit($failures === 0 ? 0 : 1);

// ============================================================================

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(public int $status, public array $headers, public string $body)
    {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}

final class Client
{
    public function __construct(private string $base, private string $jar)
    {
    }

    public function get(string $path): Response
    {
        return $this->request($path, null);
    }

    /** @param array<string, string> $data */
    public function post(string $path, array $data): Response
    {
        return $this->request($path, $data);
    }

    public function getWithBasicAuth(string $path, string $user, string $pass): Response
    {
        return $this->request($path, null, [CURLOPT_USERPWD => "{$user}:{$pass}"]);
    }

    public function jar(): string
    {
        return $this->jar;
    }

    public function request(string $path, ?array $post, array $extra = []): Response
    {
        $headers = [];
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, $extra + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->jar,
            CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    $headers[$k] = isset($headers[$k]) && $k === 'set-cookie' ? $headers[$k] . "\n" . trim($v) : trim($v);
                }
                return strlen($line);
            },
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return new Response($status, $headers, (string) $body);
    }
}

/**
 * Send POSTs concurrently (each with its own cookie jar) and return the statuses.
 *
 * @param list<array{0: Client, 1: array<string, string>}> $batch
 * @return list<int>
 */
function parallel_posts(string $base, array $batch): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($batch as [$client, $data]) {
        $ch = curl_init($base);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $client->jar(),
            CURLOPT_TIMEOUT => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    $statuses = array_map(static fn($ch): int => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $handles);
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $statuses;
}

function free_port(): int
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($sock, false);
    fclose($sock);
    return (int) substr((string) strrchr((string) $name, ':'), 1);
}

/** @param array<string, string> $env */
function start_server(int $port, ?string $router, ?string $docroot, array $env)
{
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}"];
    if ($docroot !== null) {
        array_push($cmd, '-t', $docroot);
    }
    if ($router !== null) {
        $cmd[] = $router;
    }
    $env += ['PHP_CLI_SERVER_WORKERS' => '4', 'PATH' => (string) getenv('PATH')];
    global $tmp;
    $log = "{$tmp}/server-{$port}.log";
    $proc = proc_open(array_merge([$cmd[0], '-d', 'error_reporting=-1', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', "error_log={$log}"], array_slice($cmd, 1)),
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $log . '.stderr', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('cannot start PHP server');
    }
    return $proc;
}

function wait_for(string $url): void
{
    for ($i = 0; $i < 50; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        if (curl_exec($ch) !== false) {
            return;
        }
        usleep(100000);
    }
    throw new RuntimeException("server at {$url} did not start");
}

/** @param array<string, mixed> $values */
function write_config(string $path, array $values): void
{
    file_put_contents($path, '<?php return ' . var_export($values, true) . ';');
}
