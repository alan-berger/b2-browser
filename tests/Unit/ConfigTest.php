<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\Config;
use B2Browser\ConfigException;
use B2Browser\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    use TempDir;

    protected function setUp(): void
    {
        $this->makeTempDir();
    }

    protected function tearDown(): void
    {
        $this->removeTempDir();
    }

    public function testValidConfigLoadsWithDefaults(): void
    {
        $config = Config::fromArray($this->valid());
        self::assertSame('camera-footage/', $config->prefix());
        self::assertSame(5, $config->int('rate_limit.max_attempts'));
        self::assertTrue($config->authConfigured());
        self::assertFalse($config->mfaEnabled());
    }

    public function testPrefixIsNormalised(): void
    {
        foreach (['camera-footage', '/camera-footage/', 'camera-footage//'] as $raw) {
            self::assertSame('camera-footage/', Config::fromArray($this->valid(['b2' => ['prefix' => $raw]]))->prefix());
        }
        self::assertSame('', Config::fromArray($this->valid(['b2' => ['prefix' => '']]))->prefix());
    }

    public function testListsReplaceDefaultsRatherThanMerge(): void
    {
        $config = Config::fromArray($this->valid(['media' => ['video_extensions' => ['mkv']]]));
        self::assertSame(['mkv'], $config->stringList('media.video_extensions'));
        self::assertSame(['jpg', 'jpeg', 'png', 'webp'], $config->stringList('media.image_extensions'));
    }

    /** Regression: v1.x served the bucket publicly when credentials were left empty. */
    public function testRefusesToRunWithoutCredentials(): void
    {
        $problems = $this->problems($this->valid(['auth' => ['username' => '', 'password_hash' => '']]));
        self::assertStringContainsString('auth.username and auth.password_hash must be set', implode(' ', $problems));
        Config::fromArray($this->valid(['auth' => ['username' => '', 'password_hash' => '', 'allow_no_auth' => true]]));
    }

    public function testRejectsPlaintextPassword(): void
    {
        $problems = $this->problems($this->valid(['auth' => ['password_hash' => 'hunter2hunter2']]));
        self::assertStringContainsString('not a password_hash() string', implode(' ', $problems));
    }

    public function testRejectsBadTotpSecretAndStorage(): void
    {
        $problems = $this->problems($this->valid(['auth' => ['totp_secret' => 'not-base32!'], 'storage_dir' => '/nonexistent/dir']));
        self::assertCount(2, $problems);
    }

    public function testMissingB2SettingsAreAllReported(): void
    {
        $problems = $this->problems($this->valid(['b2' => ['key_id' => '', 'bucket_id' => '']]));
        self::assertSame(['b2.key_id is not set', 'b2.bucket_id is not set'], $problems);
    }

    public function testFromFileRequiresAnArray(): void
    {
        file_put_contents($this->tmp . '/bad.php', '<?php return "nope";');
        $this->expectException(ConfigException::class);
        Config::fromFile($this->tmp . '/bad.php');
    }

    /** Regression (review finding): `'media' => []` wiped the defaults and crashed. */
    public function testEmptySectionMeansDefaults(): void
    {
        $config = Config::fromArray($this->valid(['media' => [], 'alerts' => ['ntfy' => []]]));
        self::assertSame(900, $config->int('media.signed_url_ttl'));
        self::assertSame('https://ntfy.sh', $config->string('alerts.ntfy.server'));
    }

    public function testRejectsOutOfRangeDurations(): void
    {
        $problems = $this->problems($this->valid(['media' => ['thumbnail_ttl' => 0], 'session' => ['idle_timeout' => 0]]));
        self::assertCount(2, $problems);
    }

    public function testUnknownKeyThrows(): void
    {
        $this->expectException(\OutOfBoundsException::class);
        Config::fromArray($this->valid())->get('b2.nope');
    }

    private function problems(array $values): array
    {
        try {
            Config::fromArray($values);
        } catch (ConfigException $e) {
            return $e->problems();
        }
        self::fail('expected ConfigException');
    }

    private function valid(array $override = []): array
    {
        $base = [
            'b2' => ['key_id' => 'k', 'application_key' => 'a', 'bucket_name' => 'b', 'bucket_id' => 'i', 'prefix' => 'camera-footage/'],
            'auth' => ['username' => 'alan', 'password_hash' => password_hash('correct horse battery', PASSWORD_DEFAULT)],
            'storage_dir' => $this->tmp,
        ];
        return array_replace_recursive($base, $override);
    }
}
