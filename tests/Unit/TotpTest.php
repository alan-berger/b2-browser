<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\JsonFileStore;
use B2Browser\Tests\TempDir;
use B2Browser\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    use TempDir;

    private const RFC_KEY = '12345678901234567890';
    private const RFC_KEY_BASE32 = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    /** RFC 6238 Appendix B (SHA-1), truncated to 6 digits. */
    public static function rfcVectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
            [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function testRfc6238Vectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::codeAt(self::RFC_KEY, intdiv($time, 30)));
    }

    public function testBase32DecodesRfcSecret(): void
    {
        self::assertSame(self::RFC_KEY, Totp::decodeSecret(self::RFC_KEY_BASE32));
        self::assertSame(self::RFC_KEY, Totp::decodeSecret(strtolower(chunk_split(self::RFC_KEY_BASE32, 4, ' '))));
    }

    public function testBase32RejectsInvalidAndShortSecrets(): void
    {
        self::assertNull(Totp::decodeSecret('GEZDGNBV'));            // < 80 bits
        self::assertNull(Totp::decodeSecret('GEZDGNBVGY3TQOJ1GEZD')); // '1' is not Base32
        self::assertNull(Totp::decodeSecret(''));
    }

    public function testGeneratedSecretIs160Bits(): void
    {
        $secret = Totp::generateSecret();
        self::assertSame(32, strlen($secret));
        self::assertSame(20, strlen((string) Totp::decodeSecret($secret)));
        self::assertNotSame($secret, Totp::generateSecret());
    }

    public function testAcceptsCurrentCodeOnceOnly(): void
    {
        $totp = $this->totp();
        $code = $this->codeAtOffset(0);
        self::assertTrue($totp->verifyAndConsume($code));
        self::assertFalse($totp->verifyAndConsume($code), 'replay in the same window must fail');
    }

    public function testReplayIsBlockedAcrossInstancesSharingTheStore(): void
    {
        $code = $this->codeAtOffset(0);
        self::assertTrue($this->totp()->verifyAndConsume($code));
        self::assertFalse($this->totp()->verifyAndConsume($code), 'a second session must not reuse the code');
    }

    public function testAllowsOneStepOfClockDriftEitherWay(): void
    {
        self::assertTrue($this->totp()->verifyAndConsume($this->codeAtOffset(-1)));
        self::assertTrue($this->totp()->verifyAndConsume($this->codeAtOffset(1)));
        self::assertFalse($this->totp()->verifyAndConsume($this->codeAtOffset(2)));
        self::assertFalse($this->totp()->verifyAndConsume($this->codeAtOffset(-2)));
    }

    public function testRejectsMalformedInput(): void
    {
        $totp = $this->totp();
        foreach (['', '12345', '1234567', 'abcdef', '12 34 5x'] as $bad) {
            self::assertFalse($totp->verifyAndConsume($bad), "'{$bad}' must be rejected");
        }
    }

    public function testToleratesSpacesInsideCode(): void
    {
        $code = $this->codeAtOffset(0);
        self::assertTrue($this->totp()->verifyAndConsume(substr($code, 0, 3) . ' ' . substr($code, 3)));
    }

    public function testUsedStepStoreIsPruned(): void
    {
        $totp = $this->totp();
        self::assertTrue($totp->verifyAndConsume($this->codeAtOffset(0)));
        $this->now += 30 * 10;
        self::assertTrue($totp->verifyAndConsume($this->codeAtOffset(0)));
        $stored = (new JsonFileStore($this->tmp . '/used.json'))->read();
        self::assertCount(1, $stored, 'old steps should have been pruned');
    }

    /** Regression (review finding): dashes/padding passed validation but leaked into the URI. */
    public function testSecretIsNormalisedEverywhere(): void
    {
        self::assertSame('JBSWY3DPEHPK3PXP', Totp::normalizeSecret('jbsw-y3dp ehpk-3pxp===='));
        self::assertStringContainsString('?secret=JBSWY3DPEHPK3PXP&', Totp::provisioningUri('JBSW-Y3DP-EHPK-3PXP====', 'a', 'b'));
    }

    public function testProvisioningUri(): void
    {
        $uri = Totp::provisioningUri(self::RFC_KEY_BASE32, 'alan', 'B2 File Browser');
        self::assertStringStartsWith('otpauth://totp/B2%20File%20Browser:alan?secret=' . self::RFC_KEY_BASE32, $uri);
        self::assertStringContainsString('&issuer=B2%20File%20Browser', $uri);
    }

    private function totp(): Totp
    {
        return new Totp(self::RFC_KEY_BASE32, new JsonFileStore($this->tmp . '/used.json'), fn(): int => $this->now);
    }

    private function codeAtOffset(int $steps): string
    {
        return Totp::codeAt(self::RFC_KEY, intdiv($this->now, 30) + $steps);
    }
}
