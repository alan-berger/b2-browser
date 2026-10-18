<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Immutable, validated configuration. Values are read with dotted keys,
 * e.g. $config->get('b2.prefix').
 */
final class Config
{
    private const DEFAULTS = [
        'b2' => [
            'key_id' => '', 'application_key' => '', 'bucket_name' => '', 'bucket_id' => '',
            'prefix' => '', 'enforce_key_scope' => true, 'files_per_page' => 100,
            // Undocumented: lets the test suite point the client at a mock server.
            'api_base' => 'https://api.backblazeb2.com',
        ],
        'auth' => [
            'username' => '', 'password_hash' => '', 'totp_secret' => '',
            'totp_issuer' => 'B2 File Browser', 'mfa_setup_page' => false, 'allow_no_auth' => false,
        ],
        'session' => [
            'idle_timeout' => 1800, 'absolute_timeout' => 43200,
            'cookie_name' => 'b2b_session', 'secure_cookie' => true,
        ],
        'rate_limit' => [
            'max_attempts' => 5, 'window' => 300, 'lockout_duration' => 900, 'ipv6_prefix' => 64,
        ],
        'storage_dir' => '',
        'media' => [
            'video_extensions' => ['mp4', 'm4v', 'mov', 'webm'],
            'image_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
            'signed_url_ttl' => 900, 'thumbnails' => true, 'thumbnail_ttl' => 600,
            'date_path_format' => '{Y}/{m}/{d}/',
        ],
        'site' => ['title' => 'Camera Backup Browser', 'homepage' => '/', 'hsts' => true],
        'theme' => [
            'stylesheets' => [], 'logo' => '', 'logo_alt' => '', 'heading' => '', 'heading_link' => '',
            'nav' => [], 'favicon' => '', 'toggle' => true, 'storage_key' => 'b2-theme',
            'classes' => ['wrapper' => '', 'header' => '', 'nav' => '', 'toggle' => ''],
            'login_logo' => '', 'login_logo_alt' => '',
        ],
        'log' => ['enabled' => true, 'path' => '', 'max_bytes' => 10485760],
        'alerts' => [
            'on_login' => true, 'on_failed_login' => true, 'on_lockout' => true,
            'on_download' => false, 'on_playback' => false,
            'email' => ['enabled' => false, 'from' => '', 'reply_to' => '', 'recipients' => []],
            'ntfy' => ['enabled' => false, 'server' => 'https://ntfy.sh', 'topic' => '', 'token' => ''],
        ],
        'debug' => false,
    ];

    /** @param array<string, mixed> $values */
    private function __construct(private readonly array $values)
    {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException('Configuration file not found or not readable.');
        }
        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new ConfigException('Configuration file must return an array.');
        }
        return self::fromArray($loaded);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $config = new self(self::merge(self::DEFAULTS, $values));
        $config->validate();
        return $config;
    }

    public function get(string $key): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                throw new \OutOfBoundsException("Unknown config key: {$key}");
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    public function string(string $key): string
    {
        return (string) $this->get($key);
    }

    /** @return list<string> */
    public function stringList(string $key): array
    {
        $value = $this->get($key);
        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    /** Prefix normalised to '' or a string ending in exactly one '/'. */
    public function prefix(): string
    {
        $prefix = ltrim($this->string('b2.prefix'), '/');
        return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
    }

    public function authConfigured(): bool
    {
        return $this->string('auth.username') !== '' && $this->string('auth.password_hash') !== '';
    }

    public function mfaEnabled(): bool
    {
        return $this->string('auth.totp_secret') !== '';
    }

    private function validate(): void
    {
        $problems = [];
        foreach (['key_id', 'application_key', 'bucket_name', 'bucket_id'] as $key) {
            if ($this->string("b2.{$key}") === '') {
                $problems[] = "b2.{$key} is not set";
            }
        }

        if (!$this->authConfigured() && !$this->bool('auth.allow_no_auth')) {
            $problems[] = 'auth.username and auth.password_hash must be set (or auth.allow_no_auth enabled)';
        }
        $hash = $this->string('auth.password_hash');
        if ($hash !== '' && (password_get_info($hash)['algo'] ?? null) === null) {
            $problems[] = 'auth.password_hash is not a password_hash() string — generate one with bin/hash-password.php';
        }
        if ($this->mfaEnabled() && Totp::decodeSecret($this->string('auth.totp_secret')) === null) {
            $problems[] = 'auth.totp_secret is not valid Base32 (at least 16 characters)';
        }

        $storage = $this->string('storage_dir');
        if ($storage === '') {
            $problems[] = 'storage_dir is not set';
        } elseif (!is_dir($storage) || !is_writable($storage)) {
            $problems[] = 'storage_dir does not exist or is not writable by PHP';
        }

        $perPage = $this->int('b2.files_per_page');
        if ($perPage < 1 || $perPage > 1000) {
            $problems[] = 'b2.files_per_page must be between 1 and 1000';
        }
        if ($this->int('media.signed_url_ttl') < 60 || $this->int('media.signed_url_ttl') > 604800) {
            $problems[] = 'media.signed_url_ttl must be between 60 and 604800 seconds';
        }
        if ($this->int('media.thumbnail_ttl') < 60 || $this->int('media.thumbnail_ttl') > 604800) {
            $problems[] = 'media.thumbnail_ttl must be between 60 and 604800 seconds';
        }
        foreach (['session.idle_timeout', 'session.absolute_timeout', 'rate_limit.window', 'rate_limit.lockout_duration'] as $key) {
            if ($this->int($key) < 1) {
                $problems[] = "{$key} must be a positive number of seconds";
            }
        }
        if ($this->int('rate_limit.max_attempts') < 1) {
            $problems[] = 'rate_limit.max_attempts must be at least 1';
        }
        $v6 = $this->int('rate_limit.ipv6_prefix');
        if ($v6 < 16 || $v6 > 128) {
            $problems[] = 'rate_limit.ipv6_prefix must be between 16 and 128';
        }

        $problems = array_merge($problems, $this->themeProblems());

        if ($problems !== []) {
            throw new ConfigException('Configuration problems: ' . implode('; ', $problems) . '.', $problems);
        }
    }

    /**
     * Theme assets must come from this origin (the CSP only allows 'self'
     * for styles, fonts and images); navigation links may also be absolute
     * http(s) URLs. Anything else (javascript:, data:, //host) is rejected.
     *
     * @return list<string>
     */
    private function themeProblems(): array
    {
        $problems = [];
        $sameOrigin = static fn(string $u): bool => $u !== '' && !str_starts_with($u, '//')
            && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) && !preg_match('/[\s"\'<>\\\\]/', $u);
        $link = static fn(string $u): bool => $sameOrigin($u) || (bool) preg_match('#^https?://[^\s"\'<>]+$#i', $u);

        foreach ($this->stringList('theme.stylesheets') as $css) {
            if (!$sameOrigin($css)) {
                $problems[] = "theme.stylesheets entry '{$css}' must be a path on this site";
            }
        }
        foreach (['logo', 'favicon', 'login_logo'] as $key) {
            $value = $this->string("theme.{$key}");
            if ($value !== '' && !$sameOrigin($value)) {
                $problems[] = "theme.{$key} must be a path on this site";
            }
        }
        $headingLink = $this->string('theme.heading_link');
        if ($headingLink !== '' && !$link($headingLink)) {
            $problems[] = 'theme.heading_link must be a path or an http(s) URL';
        }
        $nav = $this->get('theme.nav');
        if (!is_array($nav)) {
            $problems[] = 'theme.nav must be a list of [label, url] pairs';
        } else {
            foreach ($nav as $i => $item) {
                if (!is_array($item) || !is_string($item[0] ?? null) || !is_string($item[1] ?? null)
                    || trim($item[0]) === '' || !$link($item[1])) {
                    $problems[] = "theme.nav item {$i} must be ['Label', '/path-or-https-url']";
                }
            }
        }
        foreach (['wrapper', 'header', 'nav', 'toggle'] as $key) {
            $class = $this->string("theme.classes.{$key}");
            if ($class !== '' && !preg_match('/^[A-Za-z_-][A-Za-z0-9_ -]{0,99}$/', $class)) {
                $problems[] = "theme.classes.{$key} must be one or more CSS class names";
            }
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $this->string('theme.storage_key'))) {
            $problems[] = 'theme.storage_key may only contain letters, digits, ".", "_" and "-"';
        }
        return $problems;
    }

    /**
     * Recursive merge where list-valued settings (e.g. extension lists) are
     * replaced wholesale rather than appended to.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === [] && isset($base[$key]) && is_array($base[$key]) && !array_is_list($base[$key])) {
                continue; // an empty section means "use the defaults"
            }
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($base[$key]) && !array_is_list($value)) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
