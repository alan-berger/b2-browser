<?php
declare(strict_types=1);

namespace B2Browser;

use B2Browser\B2\B2Client;
use B2Browser\B2\B2Exception;
use B2Browser\B2\KeyScope;

/**
 * Front controller.
 *
 * Routes (all on the one entry script):
 *   POST action=login|mfa|logout   authentication (CSRF-protected)
 *   GET  (default) ?path=&page=    folder listing
 *   GET  ?date=YYYY-MM-DD          jump to a day's folder
 *   GET  ?play=<object>            player page (short-lived signed URL)
 *   GET  ?download=<object>        download proxied through PHP
 *   GET  ?mfa_setup                TOTP enrolment page (only if enabled in config)
 *
 * The v1.x ?stream= endpoint is gone: playback has used signed URLs since
 * v1.3, and the endpoint accepted Basic Auth without MFA or failure counting.
 */
final class App
{
    private Config $config;
    private Session $session;
    private Logger $logger;
    private View $view;
    private PathPolicy $paths;
    private ?B2Client $b2 = null;
    private string $ip;

    public function __construct(private readonly string $appRoot, private readonly string $configFile)
    {
        $this->view = new View($appRoot . '/views');
        $this->ip = Http::clientIp();
    }

    public function run(): void
    {
        try {
            $this->config = Config::fromFile($this->configFile);
        } catch (\Throwable $e) {
            error_log('b2-browser: ' . $e->getMessage());
            $this->page(500, 'error', [
                'title' => 'Configuration error',
                'message' => 'The application is not configured correctly. Details are in the server error log.',
                'details' => $this->debugEnabled() ? ($e instanceof ConfigException ? $e->problems() : [$e->getMessage()]) : [],
            ]);
            return;
        }

        $this->logger = new Logger(
            $this->config->bool('log.enabled'),
            $this->config->string('log.path'),
            $this->config->int('log.max_bytes'),
        );
        $this->paths = new PathPolicy($this->config->prefix());
        $this->session = new Session($this->config);

        try {
            $this->session->start();
            $this->dispatch();
        } catch (ConfigException $e) {
            // Raised after login, e.g. by the key-scope check, so the signed-in
            // owner may see the specifics.
            $this->logger->error($e->getMessage() . ': ' . implode('; ', $e->problems()), $this->ip);
            $this->page(500, 'error', [
                'title' => 'Configuration error',
                'message' => $e->getMessage() . '.',
                'details' => $this->session->isAuthenticated() || $this->config->bool('debug') ? $e->problems() : [],
            ]);
        } catch (StorageException $e) {
            $this->logger->error($e->getMessage(), $this->ip);
            $this->page(503, 'error', [
                'title' => 'Temporarily unavailable',
                'message' => 'The server could not access its state files. Please try again later.',
                'details' => $this->config->bool('debug') ? [$e->getMessage()] : [],
            ]);
        } catch (B2Exception $e) {
            $this->logger->error($e->getMessage(), $this->ip);
            $notFound = $e->isNotFound();
            $this->page($notFound ? 404 : 502, 'error', [
                'title' => $notFound ? 'Not found' : 'Storage error',
                'message' => $notFound ? 'That file does not exist.' : 'Backblaze B2 returned an error. Please try again.',
                'details' => $this->config->bool('debug') ? [$e->getMessage()] : [],
                'back' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), $this->ip);
            $this->page(500, 'error', [
                'title' => 'Error',
                'message' => 'Something went wrong. Details are in the server log.',
                'details' => $this->config->bool('debug') ? [$e->getMessage()] : [],
                'back' => true,
            ]);
        }
    }

    private function dispatch(): void
    {
        $noAuth = !$this->config->authConfigured() && $this->config->bool('auth.allow_no_auth');
        $auth = $noAuth ? null : $this->auth();

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method !== 'GET' && $method !== 'POST') {
            // HEAD would otherwise pull whole objects from B2 for downloads.
            header('Allow: GET, POST');
            $this->page(405, 'error', ['title' => 'Method not allowed', 'message' => 'Use GET or POST.']);
            return;
        }
        if (Http::isPost()) {
            $this->handlePost($auth);
            return;
        }

        if ($auth !== null && !$this->session->isAuthenticated()) {
            $this->rememberDestination();
            $this->showLogin($auth);
            return;
        }

        $this->session->touch();
        $this->checkKeyScope();

        match (true) {
            isset($_GET['download']) => $this->download($_GET['download']),
            isset($_GET['play']) => $this->play($_GET['play']),
            isset($_GET['mfa_setup']) => $this->mfaSetup(),
            isset($_GET['date']) => $this->jumpToDate($_GET['date']),
            default => $this->browse($_GET['path'] ?? null, $_GET['page'] ?? null),
        };
    }

    // ------------------------------------------------------------------ auth

    private function auth(): Auth
    {
        $storage = rtrim($this->config->string('storage_dir'), '/');
        $limiter = new RateLimiter(
            new JsonFileStore($storage . '/ratelimit.json'),
            $this->config->int('rate_limit.max_attempts'),
            $this->config->int('rate_limit.window'),
            $this->config->int('rate_limit.lockout_duration'),
            $this->config->int('rate_limit.ipv6_prefix'),
        );
        $totp = $this->config->mfaEnabled()
            ? new Totp($this->config->string('auth.totp_secret'), new JsonFileStore($storage . '/totp-used.json'))
            : null;
        return new Auth($this->config, $this->session, $limiter, $totp, $this->logger, new Notifier($this->config, $this->logger));
    }

    private function handlePost(?Auth $auth): void
    {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        if (!$this->session->checkCsrf($_POST['csrf_token'] ?? null)) {
            $this->logger->event('CSRF_REJECTED', $this->ip, $this->session->user(), ['action' => $action]);
            if ($auth !== null && !$this->session->isAuthenticated()) {
                $this->showLogin($auth, 'Your form expired. Please try again.', 400);
            } else {
                $this->page(400, 'error', ['title' => 'Request expired', 'message' => 'Please go back, reload the page and try again.', 'back' => true]);
            }
            return;
        }

        if ($action === 'logout') {
            if ($auth !== null) {
                $auth->logout($this->ip);
            }
            $this->page(200, 'logged_out', ['homepage' => $this->config->string('site.homepage')]);
            return;
        }

        if ($auth === null || $this->session->isAuthenticated()) {
            $this->redirect('?');
            return;
        }

        $result = match ($action) {
            'login' => $auth->attemptPassword($_POST, $this->ip),
            'mfa' => $auth->attemptTotp($_POST, $this->ip),
            default => ['result' => 'restart'],
        };

        switch ($result['result']) {
            case 'ok':
                $this->redirect($this->session->pull('return_to') ?? '?');
                return;
            case 'mfa':
                $this->page(200, 'mfa', ['csrf' => $this->session->csrfToken()]);
                return;
            case 'locked':
                $this->showLocked((int) ($result['remaining'] ?? 0));
                return;
            case 'error':
                if ($action === 'mfa') {
                    $this->page(401, 'mfa', ['csrf' => $this->session->csrfToken(), 'error' => $result['message'] ?? '']);
                } else {
                    $this->showLogin($auth, $result['message'] ?? '', 401);
                }
                return;
            default:
                $this->showLogin($auth, 'Please sign in again.');
        }
    }

    private function showLogin(Auth $auth, string $error = '', int $status = 200): void
    {
        $lock = $auth->lockStatus($this->ip);
        if ($lock['locked']) {
            $this->showLocked($lock['remaining']);
            return;
        }
        if ($error === '' && $auth->mfaPending()) {
            $this->page(200, 'mfa', ['csrf' => $this->session->csrfToken()]);
            return;
        }
        $this->page($status, 'login', [
            'csrf' => $this->session->csrfToken(),
            'error' => $error,
            'notice' => $this->session->pull('expired_notice') ? 'Your session expired. Please sign in again.' : '',
        ]);
    }

    private function showLocked(int $remaining): void
    {
        header('Retry-After: ' . max(1, $remaining));
        $this->page(429, 'locked', ['minutes' => max(1, intdiv($remaining + 59, 60))]);
    }

    /** Keep a bookmarked deep link (folder, page, player) across the login. */
    private function rememberDestination(): void
    {
        $query = array_intersect_key($_GET, array_flip(['path', 'page', 'play', 'date']));
        $query = array_filter($query, 'is_string');
        if ($query !== []) {
            $this->session->set('return_to', '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        }
    }

    // --------------------------------------------------------------- B2 setup

    private function b2(): B2Client
    {
        return $this->b2 ??= new B2Client(
            $this->config->string('b2.key_id'),
            $this->config->string('b2.application_key'),
            $this->config->string('b2.bucket_id'),
            $this->config->string('b2.bucket_name'),
            new JsonFileStore(rtrim($this->config->string('storage_dir'), '/') . '/b2-token.json'),
            $this->config->string('b2.api_base'),
        );
    }

    private function checkKeyScope(): void
    {
        $problems = KeyScope::problems($this->b2()->allowed(), $this->config->string('b2.bucket_id'), $this->paths->prefix());
        if ($problems === []) {
            return;
        }
        if ($this->config->bool('b2.enforce_key_scope')) {
            throw new ConfigException('B2 application key is broader than allowed', $problems);
        }
        if ($this->session->get('key_scope_warned') === null) {
            $this->logger->event('KEY_SCOPE_WARNING', $this->ip, $this->session->user(), ['problems' => $problems]);
            $this->session->set('key_scope_warned', true);
        }
    }

    // ------------------------------------------------------------------ pages

    private function browse(mixed $requestedPath, mixed $requestedPage): void
    {
        $folder = $this->paths->folder($requestedPath);
        if ($folder === null) {
            $this->page(400, 'error', ['title' => 'Invalid path', 'message' => 'That folder is outside the area this browser can show.', 'back' => true]);
            return;
        }

        $pager = new Pager($this->session);
        ['page' => $page, 'start' => $start] = $pager->resolve($folder, $requestedPage);
        $result = $this->b2()->listFileNames($folder, '/', $start, $this->config->int('b2.files_per_page'));
        $pager->recordNext($folder, $page, $result['nextFileName']);

        $media = new MediaType($this->config->stringList('media.video_extensions'), $this->config->stringList('media.image_extensions'));
        $items = [];
        foreach ($result['files'] as $file) {
            $name = (string) ($file['fileName'] ?? '');
            $action = (string) ($file['action'] ?? 'upload');
            // Only real objects and folders; skip hide markers and unfinished uploads.
            if ($name === '' || !in_array($action, ['upload', 'folder'], true) || !$this->paths->insidePrefix($name)) {
                continue;
            }
            $type = $action === 'folder' ? 'folder' : $media->classify($name);
            $info = PathInfo::parse($name);
            $items[] = [
                'name' => $name,
                'label' => $type === 'folder' ? basename(rtrim($name, '/')) : basename($name),
                'type' => $type,
                'size' => (int) ($file['contentLength'] ?? 0),
                'date' => $info->date,
                'camera' => $info->camera,
                'uploaded' => isset($file['uploadTimestamp']) ? (int) intdiv((int) $file['uploadTimestamp'], 1000) : null,
                'thumb' => null,
            ];
        }
        usort($items, static fn(array $a, array $b): int =>
            [$a['type'] !== 'folder', $a['name']] <=> [$b['type'] !== 'folder', $b['name']]);

        // One download authorisation for the whole folder covers every
        // thumbnail on the page, instead of one API call per image.
        $origins = [];
        if ($this->config->bool('media.thumbnails') && in_array('image', array_column($items, 'type'), true)) {
            $token = $this->b2()->downloadAuthorization($folder, $this->config->int('media.thumbnail_ttl'));
            foreach ($items as &$item) {
                if ($item['type'] === 'image') {
                    $item['thumb'] = $this->b2()->withToken($this->b2()->objectUrl($item['name']), $token);
                }
            }
            unset($item);
            $origins[] = $this->b2()->downloadOrigin();
        }

        $this->page(200, 'browse', [
            'items' => $items,
            'folder' => $folder,
            'crumbs' => $this->crumbs($folder),
            'page' => $page,
            'hasNext' => $result['nextFileName'] !== null,
            'dateJump' => $this->config->string('media.date_path_format') !== '',
            'today' => date('Y-m-d'),
        ], $origins);
    }

    private function jumpToDate(mixed $date): void
    {
        $relative = is_string($date) ? PathInfo::folderForDate($date, $this->config->string('media.date_path_format')) : null;
        $folder = $relative === null ? null : $this->paths->folder($this->paths->prefix() . $relative);
        if ($folder === null) {
            $this->page(400, 'error', ['title' => 'Invalid date', 'message' => 'Please choose a valid date.', 'back' => true]);
            return;
        }
        $this->redirect('?' . http_build_query(['path' => $folder], '', '&', PHP_QUERY_RFC3986));
    }

    private function play(mixed $requested): void
    {
        $file = $this->paths->file($requested);
        if ($file === null) {
            $this->page(400, 'error', ['title' => 'Invalid file', 'message' => 'That file is outside the area this browser can show.', 'back' => true]);
            return;
        }
        $this->logger->event('VIDEO_PLAYBACK', $this->ip, $this->session->user(), ['filename' => $file]);
        $this->alertFileAccess('on_playback', 'Video playback started', $file);

        $ttl = $this->config->int('media.signed_url_ttl');
        $signed = $this->b2()->signedUrl($file, $ttl);
        $info = PathInfo::parse($file);
        $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $this->page(200, 'player', [
            'file' => $file,
            'label' => basename($file),
            'src' => $signed,
            'mime' => MediaType::videoMime($file),
            'date' => $info->date,
            'camera' => $info->camera,
            'parent' => ($slash = strrpos($file, '/')) === false ? '' : substr($file, 0, $slash + 1),
            'ttlMinutes' => intdiv($ttl, 60),
            'ios' => (bool) preg_match('/iPhone|iPad|iPod/i', $agent),
        ], [$this->b2()->downloadOrigin()]);
    }

    private function download(mixed $requested): void
    {
        $file = $this->paths->file($requested);
        if ($file === null) {
            $this->page(400, 'error', ['title' => 'Invalid file', 'message' => 'That file is outside the area this browser can show.', 'back' => true]);
            return;
        }
        $this->logger->event('FILE_DOWNLOAD', $this->ip, $this->session->user(), ['filename' => $file]);
        $this->alertFileAccess('on_download', 'File downloaded', $file);

        // Long transfers must not hold the session lock (other tabs would hang).
        $this->session->close();
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        try {
            $this->b2()->streamObject(
                $file,
                static function (int $status, array $headers) use ($file): bool {
                    $type = strtolower($headers['content-type'] ?? '');
                    $safeType = preg_match('#^(video|image)/[a-z0-9.+-]+$#', $type) ? $type : 'application/octet-stream';
                    header('Content-Type: ' . $safeType);
                    if (isset($headers['content-length']) && ctype_digit($headers['content-length'])) {
                        header('Content-Length: ' . $headers['content-length']);
                    }
                    header('Content-Disposition: ' . Http::contentDisposition($file));
                    header('X-Content-Type-Options: nosniff');
                    header("Content-Security-Policy: default-src 'none'; sandbox");
                    header('Cache-Control: private, no-store');
                    header('Referrer-Policy: no-referrer');
                    header('X-Accel-Buffering: no');
                    return true;
                },
                static function (string $chunk): void {
                    echo $chunk;
                    flush();
                },
            );
        } catch (B2Exception $e) {
            if (headers_sent()) {
                // Body already started; nothing can be shown. The client sees a truncated file.
                $this->logger->error('download interrupted: ' . $e->getMessage(), $this->ip);
                return;
            }
            // Headers may be queued (attachment, Content-Length) without any
            // output yet; drop them so the error page is not saved as the file.
            header_remove();
            throw $e;
        }
    }

    private function mfaSetup(): void
    {
        if (!$this->config->bool('auth.mfa_setup_page') || !$this->config->mfaEnabled() || !$this->session->isAuthenticated()) {
            $this->page(404, 'error', ['title' => 'Not found', 'message' => 'The MFA setup page is disabled.', 'back' => true]);
            return;
        }
        $user = (string) $this->session->user();
        $secret = $this->config->string('auth.totp_secret');
        $this->logger->event('MFA_SETUP_VIEWED', $this->ip, $user);
        (new Notifier($this->config, $this->logger))->send('MFA secret viewed', 'warning', [
            'Username' => $user,
            'IP address' => $this->ip,
            'Time' => date('Y-m-d H:i:s T'),
        ], 'The TOTP secret was displayed. If this was not you, generate a new secret immediately.');

        $this->page(200, 'mfa_setup', [
            'secret' => trim(chunk_split(Totp::normalizeSecret($secret), 4, ' ')),
            'uri' => Totp::provisioningUri($secret, $user, $this->config->string('auth.totp_issuer')),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /** @return list<array{label: string, path: string}> */
    private function crumbs(string $folder): array
    {
        $crumbs = [];
        $path = $this->paths->prefix();
        foreach (explode('/', trim($this->paths->relative($folder), '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $path .= $segment . '/';
            $crumbs[] = ['label' => $segment, 'path' => $path];
        }
        return $crumbs;
    }

    private function alertFileAccess(string $toggle, string $title, string $file): void
    {
        if (!$this->config->bool("alerts.{$toggle}")) {
            return;
        }
        (new Notifier($this->config, $this->logger))->send($title, 'info', [
            'Username' => (string) ($this->session->user() ?? 'N/A'),
            'IP address' => $this->ip,
            'File' => $file,
            'Time' => date('Y-m-d H:i:s T'),
        ]);
    }

    private function redirect(string $location): void
    {
        // Only ever redirect to this script's own query strings.
        if (!str_starts_with($location, '?')) {
            $location = '?';
        }
        header('Location: ' . $location, true, 303);
        header('Cache-Control: no-store');
    }

    /**
     * @param array<string, mixed> $vars
     * @param list<string> $mediaOrigins
     */
    private function page(int $status, string $template, array $vars = [], array $mediaOrigins = []): void
    {
        $authenticated = isset($this->session) && $this->session->isAuthenticated();
        $vars += [
            'siteTitle' => isset($this->config) ? $this->config->string('site.title') : 'Camera Backup Browser',
            'homepage' => isset($this->config) ? $this->config->string('site.homepage') : '/',
            'authenticated' => $authenticated,
            'user' => $authenticated ? $this->session->user() : null,
            'csrf' => isset($this->session) && session_status() === PHP_SESSION_ACTIVE ? $this->session->csrfToken() : '',
            'minutesLeft' => $authenticated ? intdiv($this->session->secondsRemaining(), 60) : 0,
            'mfaSetupLink' => isset($this->config) && $authenticated && $this->config->bool('auth.mfa_setup_page') && $this->config->mfaEnabled(),
            'debug' => isset($this->config) && $this->config->bool('debug'),
            'theme' => $this->themeVars(),
        ];
        $html = $this->view->render($template, $vars);

        http_response_code($status);
        Http::sendSecurityHeaders($mediaOrigins, isset($this->config) ? $this->config->bool('site.hsts') : true);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
    }

    /**
     * Branding for the layout. Before the config has loaded (configuration
     * error page) the plain built-in look is used.
     *
     * @return array{stylesheets: list<string>, logo: string, logoAlt: string, heading: string,
     *               headingLink: string, nav: list<array{0: string, 1: string}>, favicon: string,
     *               toggle: bool, storageKey: string}
     */
    private function themeVars(): array
    {
        if (!isset($this->config)) {
            return ['stylesheets' => [], 'logo' => '', 'logoAlt' => '', 'heading' => '', 'headingLink' => '',
                'nav' => [], 'favicon' => '', 'toggle' => true, 'storageKey' => 'b2-theme', 'classes' => null];
        }
        $nav = [];
        foreach ((array) $this->config->get('theme.nav') as $item) {
            $nav[] = [(string) $item[0], (string) $item[1]];
        }
        return [
            'stylesheets' => $this->config->stringList('theme.stylesheets'),
            'logo' => $this->config->string('theme.logo'),
            'logoAlt' => $this->config->string('theme.logo_alt'),
            'heading' => $this->config->string('theme.heading'),
            'headingLink' => $this->config->string('theme.heading_link') ?: $this->config->string('site.homepage'),
            'nav' => $nav,
            'favicon' => $this->config->string('theme.favicon'),
            'toggle' => $this->config->bool('theme.toggle'),
            'storageKey' => $this->config->string('theme.storage_key'),
            // Site markup mode: when the wrapper, header and nav class names
            // are all given, the layout reproduces the host site's own header
            // so its stylesheet positions it identically on every page.
            'classes' => $this->config->string('theme.classes.wrapper') !== ''
                && $this->config->string('theme.classes.header') !== ''
                && $this->config->string('theme.classes.nav') !== ''
                ? [
                    'wrapper' => $this->config->string('theme.classes.wrapper'),
                    'header' => $this->config->string('theme.classes.header'),
                    'nav' => $this->config->string('theme.classes.nav'),
                    'toggle' => $this->config->string('theme.classes.toggle'),
                ]
                : null,
        ];
    }

    private function debugEnabled(): bool
    {
        // Config failed to load, so read the flag defensively from the file.
        try {
            $raw = @include $this->configFile;
            return is_array($raw) && ($raw['debug'] ?? false) === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
