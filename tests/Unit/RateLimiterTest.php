<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\JsonFileStore;
use B2Browser\RateLimiter;
use B2Browser\StorageException;
use B2Browser\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    use TempDir;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testLocksAtThresholdAndReportsItOnce(): void
    {
        $limiter = $this->limiter();
        for ($i = 1; $i <= 4; $i++) {
            $r = $limiter->attempt('192.0.2.1');
            self::assertTrue($r['allowed']);
            self::assertSame($i, $r['attempts']);
            self::assertFalse($r['newly_locked']);
        }
        self::assertFalse($limiter->status('192.0.2.1')['locked']);

        $fifth = $limiter->attempt('192.0.2.1');
        self::assertTrue($fifth['allowed'], 'the 5th attempt is still within budget');
        self::assertTrue($fifth['newly_locked']);
        self::assertSame(900, $fifth['remaining']);

        $sixth = $limiter->attempt('192.0.2.1');
        self::assertFalse($sixth['allowed']);
        self::assertFalse($sixth['newly_locked'], 'lock must not be re-announced');
        self::assertTrue($limiter->status('192.0.2.1')['locked']);
    }

    /**
     * Regression (review finding): with a separate check and record, parallel
     * requests all passed the check while password_verify ran. Reservation is
     * now atomic, so no more than max_attempts can ever be verified per cycle,
     * however the requests interleave.
     */
    public function testAttemptsAreReservedBeforeVerification(): void
    {
        $limiter = $this->limiter();
        $allowed = 0;
        for ($i = 0; $i < 20; $i++) {
            // Simulates 20 in-flight requests: none has finished verifying.
            $allowed += $limiter->attempt('192.0.2.1')['allowed'] ? 1 : 0;
        }
        self::assertSame(5, $allowed);
    }

    /** Regression: v1.x purged locked entries once the 300 s counting window passed. */
    public function testLockLastsTheFullLockoutDuration(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 5; $i++) {
            $limiter->attempt('192.0.2.1');
        }
        foreach ([301, 600, 899] as $elapsed) {
            $this->now = 1_000_000 + $elapsed;
            self::assertTrue($limiter->status('192.0.2.1')['locked'], "still locked after {$elapsed}s");
            self::assertFalse($limiter->attempt('192.0.2.1')['allowed']);
        }
        $this->now = 1_000_000 + 900;
        self::assertFalse($limiter->status('192.0.2.1')['locked']);
        self::assertSame(0, $limiter->status('192.0.2.1')['attempts'], 'counter resets after the lock is served');
    }

    public function testAttemptsOutsideTheWindowAreForgotten(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 4; $i++) {
            $limiter->attempt('192.0.2.1');
        }
        $this->now += 301;
        $r = $limiter->attempt('192.0.2.1');
        self::assertSame(1, $r['attempts']);
        self::assertFalse($r['newly_locked']);
    }

    public function testRefundGivesBackAnAttempt(): void
    {
        $limiter = $this->limiter();
        $limiter->attempt('192.0.2.1');
        $limiter->attempt('192.0.2.1');
        $limiter->refund('192.0.2.1', false);
        self::assertSame(1, $limiter->status('192.0.2.1')['attempts']);
    }

    public function testRefundLiftsOnlyTheLockItsOwnAttemptApplied(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 4; $i++) {
            $limiter->attempt('192.0.2.1');
        }
        $fifth = $limiter->attempt('192.0.2.1'); // correct password on the 5th try
        self::assertTrue($fifth['newly_locked']);
        $limiter->refund('192.0.2.1', $fifth['newly_locked']);
        self::assertFalse($limiter->status('192.0.2.1')['locked'], 'the user can continue to the TOTP step');
        $totp = $limiter->attempt('192.0.2.1');
        self::assertTrue($totp['allowed'] && $totp['newly_locked'], 'the TOTP attempt is the last in budget');

        $limiter->refund('192.0.2.1', false);
        self::assertTrue($limiter->status('192.0.2.1')['locked'], 'a refund without liftLock keeps the lock');
    }

    public function testAddressesAreIndependentAndClearable(): void
    {
        $limiter = $this->limiter();
        for ($i = 0; $i < 5; $i++) {
            $limiter->attempt('192.0.2.1');
        }
        self::assertFalse($limiter->status('192.0.2.2')['locked']);
        $limiter->attempt('192.0.2.2');
        $limiter->clear('192.0.2.2');
        self::assertSame(0, $limiter->status('192.0.2.2')['attempts']);
        self::assertTrue($limiter->status('192.0.2.1')['locked']);
    }

    public function testIpv6AddressesInTheSame64ShareABucket(): void
    {
        $limiter = $this->limiter();
        for ($i = 1; $i <= 5; $i++) {
            $limiter->attempt("2001:db8:1:2::{$i}"); // a different address each time
        }
        self::assertTrue($limiter->status('2001:db8:1:2:ffff::1')['locked']);
        self::assertFalse($limiter->status('2001:db8:1:3::1')['locked'], 'a different /64 is unaffected');
    }

    public function testKeyNormalisation(): void
    {
        $limiter = $this->limiter();
        self::assertSame('192.0.2.7', $limiter->key('192.0.2.7'));
        self::assertSame('192.0.2.7', $limiter->key('::ffff:192.0.2.7'));
        self::assertSame('2001:db8:1:2::/64', $limiter->key('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame('2001:db8::/48', $this->limiter(48)->key('2001:db8:0:ffff::1'));
        self::assertSame('2001:db8::1/128', $this->limiter(128)->key('2001:db8::1'));
    }

    public function testFailsClosedWhenStateCannotBeWritten(): void
    {
        $limiter = new RateLimiter(new JsonFileStore($this->tmp . '/missing-dir/rl.json'), 5, 300, 900);
        $this->expectException(StorageException::class);
        $limiter->status('192.0.2.1');
    }

    private function limiter(int $v6 = 64): RateLimiter
    {
        return new RateLimiter(new JsonFileStore($this->tmp . '/rl.json'), 5, 300, 900, $v6, fn(): int => $this->now);
    }
}
