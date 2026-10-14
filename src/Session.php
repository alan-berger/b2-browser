<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Session handling with hardened cookie settings, idle and absolute
 * timeouts, and CSRF tokens.
 *
 * v1.x relied on php.ini for cookie flags and only had an idle timeout that
 * every request extended indefinitely.
 */
final class Session
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(private readonly Config $config, ?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function start(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.cookie_httponly', '1');
        // php.ini's gc_maxlifetime (often 1440 s) would otherwise delete
        // session files before the configured timeouts are reached.
        ini_set('session.gc_maxlifetime', (string) max(
            $this->config->int('session.idle_timeout'),
            $this->config->int('session.absolute_timeout'),
        ));
        session_name($this->config->string('session.cookie_name'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath(),
            'secure' => $this->config->bool('session.secure_cookie'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        if ($this->isAuthenticated() && $this->expired()) {
            // Discard the old data and ID in place; the browser keeps one cookie.
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['expired_notice'] = true;
        }
    }

    public function isAuthenticated(): bool
    {
        return ($_SESSION['authenticated'] ?? false) === true;
    }

    public function user(): ?string
    {
        return isset($_SESSION['username']) ? (string) $_SESSION['username'] : null;
    }

    public function login(string $username): void
    {
        session_regenerate_id(true);
        $now = ($this->clock)();
        $returnTo = $_SESSION['return_to'] ?? null;
        $_SESSION = [
            'return_to' => $returnTo,
            'authenticated' => true,
            'username' => $username,
            'created' => $now,
            'last_activity' => $now,
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
    }

    /** Record activity so the idle timer restarts. */
    public function touch(): void
    {
        $_SESSION['last_activity'] = ($this->clock)();
    }

    /** Seconds until the session ends from whichever timeout comes first. */
    public function secondsRemaining(): int
    {
        $now = ($this->clock)();
        $idle = (int) ($_SESSION['last_activity'] ?? $now) + $this->config->int('session.idle_timeout') - $now;
        $absolute = (int) ($_SESSION['created'] ?? $now) + $this->config->int('session.absolute_timeout') - $now;
        return max(0, min($idle, $absolute));
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => 1,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_destroy();
        }
    }

    /** Release the session lock early (long downloads must not block other tabs). */
    public function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public function csrfToken(): string
    {
        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public function checkCsrf(mixed $submitted): bool
    {
        return is_string($submitted) && isset($_SESSION['csrf_token'])
            && is_string($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $submitted);
    }

    /** One-shot flag (e.g. "your session expired") shown on the next page. */
    public function pull(string $key): mixed
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $value;
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    private function expired(): bool
    {
        $now = ($this->clock)();
        $last = (int) ($_SESSION['last_activity'] ?? 0);
        $created = (int) ($_SESSION['created'] ?? 0);
        return $now - $last > $this->config->int('session.idle_timeout')
            || $now - $created > $this->config->int('session.absolute_timeout');
    }

    private function cookiePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        return $dir === '' ? '/' : $dir . '/';
    }
}
