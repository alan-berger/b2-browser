<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Counts authentication failures per client address and locks an address out
 * once it reaches the threshold within the window.
 *
 * Fixes over v1.x:
 *  - A lock now lasts the full lockout duration. Previously the window purge
 *    removed locked entries ATTEMPT_WINDOW seconds after the first failure,
 *    cutting a 15-minute lockout to roughly 5 minutes.
 *  - Attempts are reserved atomically before verification (attempt()), so
 *    parallel requests cannot each pass the lock check while slow password
 *    or TOTP verification runs. The lock is reported exactly once, so alerts
 *    fire once instead of on every blocked request.
 *  - IPv6 clients are grouped by network prefix (default /64); otherwise one
 *    host can rotate through 2^64 addresses and never be locked.
 *  - Storage errors throw (fail closed) instead of silently allowing.
 */
final class RateLimiter
{
    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly JsonFileStore $store,
        private readonly int $maxAttempts,
        private readonly int $window,
        private readonly int $lockoutDuration,
        private readonly int $ipv6Prefix = 64,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array{locked: bool, remaining: int, attempts: int} */
    public function status(string $ip): array
    {
        $key = $this->key($ip);
        $now = ($this->clock)();
        return $this->store->transact(function (array $data) use ($key, $now): array {
            $data = $this->purge($data, $now);
            $entry = $data[$key] ?? null;
            $locked = $entry !== null && ($entry['locked_until'] ?? 0) > $now;
            return [$data, [
                'locked' => $locked,
                'remaining' => $locked ? $entry['locked_until'] - $now : 0,
                'attempts' => $entry['count'] ?? 0,
            ]];
        });
    }

    /**
     * Reserve one authentication attempt for an address, atomically.
     *
     * The attempt is counted BEFORE the credentials are checked, in the same
     * locked transaction as the lock test. If counting it reaches the
     * threshold, the lock is applied at once, so parallel requests cannot all
     * pass the check while slow verification (password_verify, TOTP) runs
     * and only record their failures afterwards. Exactly max_attempts
     * verifications can happen per lockout cycle. On success, call clear()
     * (or refund() for a first factor that passed).
     *
     * @return array{allowed: bool, attempts: int, newly_locked: bool, remaining: int}
     */
    public function attempt(string $ip): array
    {
        $key = $this->key($ip);
        $now = ($this->clock)();
        return $this->store->transact(function (array $data) use ($key, $now): array {
            $data = $this->purge($data, $now);
            $entry = $data[$key] ?? ['count' => 0, 'first_attempt' => $now];
            if (($entry['locked_until'] ?? 0) > $now) {
                return [null, ['allowed' => false, 'attempts' => $entry['count'], 'newly_locked' => false,
                    'remaining' => $entry['locked_until'] - $now]];
            }
            $entry['count']++;
            $newlyLocked = false;
            if ($entry['count'] >= $this->maxAttempts) {
                $entry['locked_until'] = $now + $this->lockoutDuration;
                $newlyLocked = true;
            }
            $data[$key] = $entry;
            return [$data, ['allowed' => true, 'attempts' => $entry['count'], 'newly_locked' => $newlyLocked,
                'remaining' => $newlyLocked ? $this->lockoutDuration : 0]];
        });
    }

    /**
     * Give back one reserved attempt (the password step passed but the
     * session still needs its second factor). Lifts a lock only if that
     * reservation was the one that applied it.
     */
    public function refund(string $ip, bool $liftLock): void
    {
        $key = $this->key($ip);
        $now = ($this->clock)();
        $this->store->transact(function (array $data) use ($key, $now, $liftLock): array {
            $data = $this->purge($data, $now);
            if (!isset($data[$key])) {
                return [null, null];
            }
            $data[$key]['count'] = max(0, $data[$key]['count'] - 1);
            if ($liftLock) {
                unset($data[$key]['locked_until']);
            }
            return [$data, null];
        });
    }

    public function clear(string $ip): void
    {
        $key = $this->key($ip);
        $this->store->transact(static function (array $data) use ($key): array {
            if (!isset($data[$key])) {
                return [null, null];
            }
            unset($data[$key]);
            return [$data, null];
        });
    }

    /**
     * The bucket an address is counted in: IPv4 addresses as-is, IPv6
     * addresses truncated to the configured prefix length.
     */
    public function key(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip; // IPv4 or unparseable (counted verbatim)
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is one IPv4 client.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }
        $bits = $this->ipv6Prefix;
        $masked = '';
        for ($i = 0; $i < 16; $i++) {
            $keep = max(0, min(8, $bits - $i * 8));
            $mask = $keep === 0 ? 0 : (0xFF << (8 - $keep)) & 0xFF;
            $masked .= chr(ord($packed[$i]) & $mask);
        }
        return inet_ntop($masked) . '/' . $bits;
    }

    /**
     * @param array<string, array{count: int, first_attempt: int, locked_until?: int}> $data
     * @return array<string, array{count: int, first_attempt: int, locked_until?: int}>
     */
    private function purge(array $data, int $now): array
    {
        foreach ($data as $key => $entry) {
            if (!is_array($entry) || !isset($entry['count'], $entry['first_attempt'])) {
                unset($data[$key]);
                continue;
            }
            $lockedUntil = $entry['locked_until'] ?? 0;
            if ($lockedUntil > $now) {
                continue; // an active lock survives regardless of the counting window
            }
            if ($lockedUntil > 0 || $now - $entry['first_attempt'] > $this->window) {
                unset($data[$key]); // lock served, or counting window elapsed
            }
        }
        return $data;
    }
}
