<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * RFC 6238 TOTP (HMAC-SHA1, 6 digits, 30-second step) with single-use codes.
 *
 * A code is accepted for the previous, current or next step (±30 s clock
 * drift). Each accepted step is consumed atomically in a store shared by all
 * sessions, so a code cannot be replayed from another browser — and, unlike
 * v1.x, two simultaneous submissions of the same code cannot both succeed,
 * because the check and the mark happen under one lock.
 */
final class Totp
{
    public const PERIOD = 30;
    private const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly string $secret,
        private readonly JsonFileStore $usedSteps,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** Verify a submitted code and consume its time step. */
    public function verifyAndConsume(string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $key = self::decodeSecret($this->secret);
        if ($key === null) {
            return false;
        }

        $current = intdiv(($this->clock)(), self::PERIOD);
        $matched = null;
        // Check every candidate step without short-circuiting, so timing does
        // not reveal which step matched.
        foreach ([-1, 0, 1] as $offset) {
            if (hash_equals(self::codeAt($key, $current + $offset), $code)) {
                $matched ??= $current + $offset;
            }
        }
        if ($matched === null) {
            return false;
        }

        return $this->usedSteps->transact(static function (array $used) use ($matched, $current): array {
            $used = array_values(array_filter(
                $used,
                static fn($step): bool => is_int($step) && abs($current - $step) <= 3,
            ));
            if (in_array($matched, $used, true)) {
                return [$used, false]; // replay
            }
            $used[] = $matched;
            return [$used, true];
        });
    }

    /** The code for a raw key at a given time step (exposed for tests and the setup tool). */
    public static function codeAt(string $key, int $step): string
    {
        $counter = pack('J', $step); // 64-bit big-endian
        $hash = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Strict Base32 decode. Returns null for invalid input or secrets shorter
     * than 80 bits. Spaces and padding are tolerated.
     */
    public static function decodeSecret(string $secret): ?string
    {
        $secret = self::normalizeSecret($secret);
        if (strlen($secret) < 16 || strspn($secret, self::BASE32) !== strlen($secret)) {
            return null;
        }
        $buffer = 0;
        $bits = 0;
        $out = '';
        foreach (str_split($secret) as $char) {
            $buffer = (($buffer << 5) | strpos(self::BASE32, $char)) & 0xFFFF;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $out;
    }

    /** Canonical form of a Base32 secret: upper case, no spaces, dashes or padding. */
    public static function normalizeSecret(string $secret): string
    {
        return strtoupper(rtrim(str_replace([' ', '-'], '', $secret), '='));
    }

    /** A new random 160-bit secret, Base32-encoded (RFC 4226 recommends 160 bits). */
    public static function generateSecret(): string
    {
        $bytes = random_bytes(20);
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . rawurlencode(self::normalizeSecret($secret))
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }
}
