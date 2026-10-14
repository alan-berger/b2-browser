<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Form-based login: username + password, then (if configured) a TOTP code.
 *
 * Replaces HTTP Basic Auth. With Basic Auth the browser cached credentials
 * and re-sent them after "logout", and the ?stream= endpoint accepted them
 * without MFA or failure counting. Here every failure, at either step, is
 * counted against the client's address, and the failure counter is only
 * cleared once both factors have succeeded.
 */
final class Auth
{
    private const MFA_STEP_TTL = 300;
    private const FAILED_LOGIN_ALERT_AT = 3;

    public function __construct(
        private readonly Config $config,
        private readonly Session $session,
        private readonly RateLimiter $limiter,
        private readonly ?Totp $totp,
        private readonly Logger $logger,
        private readonly Notifier $notifier,
    ) {
    }

    /** @return array{locked: bool, remaining: int, attempts: int} */
    public function lockStatus(string $ip): array
    {
        return $this->limiter->status($ip);
    }

    public function mfaPending(): bool
    {
        $pending = $this->session->get('mfa_pending');
        return is_array($pending) && isset($pending['at']) && time() - (int) $pending['at'] <= self::MFA_STEP_TTL;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{result: 'ok'|'mfa'|'error'|'locked', message?: string, remaining?: int}
     */
    public function attemptPassword(array $post, string $ip): array
    {
        // Reserve the attempt before verifying (see RateLimiter::attempt()).
        $slot = $this->limiter->attempt($ip);
        if (!$slot['allowed']) {
            return ['result' => 'locked', 'remaining' => $slot['remaining']];
        }

        $username = is_string($post['username'] ?? null) ? $post['username'] : '';
        $password = is_string($post['password'] ?? null) ? $post['password'] : '';

        $expectedUser = $this->config->string('auth.username');
        $hash = $this->config->string('auth.password_hash');
        $userOk = hash_equals($expectedUser, $username);
        // Always run password_verify so a wrong username costs the same time
        // as a wrong password and usernames cannot be enumerated.
        $passOk = password_verify($password, $hash);

        if (!$userOk || !$passOk) {
            return $this->fail($ip, $slot, 'LOGIN_FAILED', 'Invalid username or password.', [
                'reason' => 'invalid credentials',
                'username_attempt' => mb_substr($username, 0, 64),
            ]);
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->logger->event('PASSWORD_REHASH_RECOMMENDED', $ip, $expectedUser,
                ['note' => 'regenerate auth.password_hash with bin/hash-password.php']);
        }

        if ($this->totp !== null) {
            // The first factor passed: give the attempt back (and lift a lock
            // only if this very attempt applied it); the TOTP step reserves its own.
            $this->limiter->refund($ip, $slot['newly_locked']);
            session_regenerate_id(true);
            $this->session->set('mfa_pending', ['user' => $expectedUser, 'at' => time()]);
            return ['result' => 'mfa'];
        }

        $this->complete($ip, $expectedUser, 'password');
        return ['result' => 'ok'];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{result: 'ok'|'error'|'locked'|'restart', message?: string, remaining?: int}
     */
    public function attemptTotp(array $post, string $ip): array
    {
        if ($this->totp === null || !$this->mfaPending()) {
            $this->session->remove('mfa_pending');
            return ['result' => 'restart'];
        }
        $slot = $this->limiter->attempt($ip);
        if (!$slot['allowed']) {
            $this->session->remove('mfa_pending');
            return ['result' => 'locked', 'remaining' => $slot['remaining']];
        }

        $code = is_string($post['code'] ?? null) ? $post['code'] : '';
        if (!$this->totp->verifyAndConsume($code)) {
            // Invalid and replayed codes get the same response.
            $result = $this->fail($ip, $slot, 'MFA_FAILED', 'Invalid or already-used code.', ['reason' => 'invalid or replayed TOTP code']);
            if ($result['result'] === 'locked') {
                $this->session->remove('mfa_pending');
            }
            return $result;
        }

        $user = (string) ($this->session->get('mfa_pending')['user'] ?? $this->config->string('auth.username'));
        $this->complete($ip, $user, 'password + TOTP');
        return ['result' => 'ok'];
    }

    public function logout(string $ip): void
    {
        $this->logger->event('LOGOUT', $ip, $this->session->user());
        $this->session->destroy();
    }

    private function complete(string $ip, string $user, string $method): void
    {
        $this->limiter->clear($ip);
        $this->session->login($user);
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 200);
        $this->logger->event('LOGIN_SUCCESS', $ip, $user, ['method' => $method, 'user_agent' => $agent]);

        if ($this->config->bool('alerts.on_login')) {
            $note = $this->totp === null
                ? 'MFA is not enabled. Consider setting auth.totp_secret.'
                : 'If you do not recognise this login, change your password and TOTP secret.';
            $this->notifier->send('Successful login', 'info', [
                'Username' => $user,
                'IP address' => $ip,
                'Method' => $method,
                'Time' => date('Y-m-d H:i:s T'),
                'Browser' => $agent,
            ], $note);
        }
    }

    /**
     * Report a failed attempt that was already counted by attempt().
     *
     * @param array{allowed: bool, attempts: int, newly_locked: bool, remaining: int} $slot
     * @param array<string, string> $details
     * @return array{result: 'error'|'locked', message?: string, remaining?: int}
     */
    private function fail(string $ip, array $slot, string $event, string $message, array $details): array
    {
        $this->logger->event($event, $ip, null, $details + ['attempts' => $slot['attempts']]);

        if ($slot['newly_locked']) {
            $this->logger->event('ADDRESS_LOCKED', $ip, null, [
                'attempts' => $slot['attempts'],
                'lockout_seconds' => $slot['remaining'],
                'bucket' => $this->limiter->key($ip),
            ]);
            if ($this->config->bool('alerts.on_lockout')) {
                $this->notifier->send('Address locked out', 'danger', [
                    'IP address' => $ip,
                    'Counted as' => $this->limiter->key($ip),
                    'Failed attempts' => (string) $slot['attempts'],
                    'Locked for' => (string) intdiv($slot['remaining'] + 59, 60) . ' minutes',
                    'Time' => date('Y-m-d H:i:s T'),
                ], 'If this was not you, someone may be trying to access your footage.');
            }
            return ['result' => 'locked', 'remaining' => $slot['remaining']];
        }

        if ($slot['attempts'] === self::FAILED_LOGIN_ALERT_AT && $this->config->bool('alerts.on_failed_login')) {
            $this->notifier->send('Repeated failed logins', 'warning', [
                'IP address' => $ip,
                'Failed attempts' => (string) $slot['attempts'],
                'Last failure' => $details['reason'],
                'Time' => date('Y-m-d H:i:s T'),
            ], 'The address will be locked after ' . $this->config->int('rate_limit.max_attempts') . ' failures.');
        }
        return ['result' => 'error', 'message' => $message];
    }
}
