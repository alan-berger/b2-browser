<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\B2\KeyScope;
use PHPUnit\Framework\TestCase;

final class KeyScopeTest extends TestCase
{
    private const BUCKET = '4a48fe8875c6214145260818';

    public function testProperlyRestrictedKeyPasses(): void
    {
        self::assertSame([], KeyScope::problems($this->allowed(), self::BUCKET, 'camera-footage/'));
    }

    public function testReadOnlyKeyFromTheWebUiPasses(): void
    {
        $allowed = $this->allowed(['listBuckets', 'listAllBucketNames', 'readBuckets', 'listFiles', 'readFiles',
            'shareFiles', 'readBucketEncryption', 'readBucketRetentions', 'readFileRetentions', 'readFileLegalHolds']);
        self::assertSame([], KeyScope::problems($allowed, self::BUCKET, 'camera-footage/'));
    }

    public function testConfiguredPrefixMayBeNarrowerThanTheKeys(): void
    {
        self::assertSame([], KeyScope::problems($this->allowed(prefix: 'camera-footage/'), self::BUCKET, 'camera-footage/front/'));
    }

    public function testMasterKeyIsRejected(): void
    {
        $allowed = ['buckets' => [], 'capabilities' => ['listFiles', 'readFiles', 'shareFiles', 'writeFiles', 'deleteFiles', 'writeBuckets'], 'namePrefix' => null];
        $problems = KeyScope::problems($allowed, self::BUCKET, 'camera-footage/');
        self::assertCount(3, $problems);
        self::assertStringContainsString('writeFiles, deleteFiles, writeBuckets', $problems[0]);
        self::assertStringContainsString('not restricted to a bucket', $problems[1]);
        self::assertStringContainsString('no namePrefix', $problems[2]);
    }

    public function testMissingCapabilityIsReported(): void
    {
        $problems = KeyScope::problems($this->allowed(['listFiles', 'readFiles']), self::BUCKET, 'camera-footage/');
        self::assertSame(['key lacks required capabilities: shareFiles'], $problems);
    }

    public function testBypassGovernanceIsRejected(): void
    {
        $problems = KeyScope::problems($this->allowed(['listFiles', 'readFiles', 'shareFiles', 'bypassGovernance']), self::BUCKET, 'camera-footage/');
        self::assertCount(1, $problems);
    }

    public function testWrongBucketOrPrefixIsRejected(): void
    {
        self::assertCount(1, KeyScope::problems($this->allowed(), 'other-bucket', 'camera-footage/'));
        self::assertCount(1, KeyScope::problems($this->allowed(prefix: 'camera-footage/front/'), self::BUCKET, 'camera-footage/'));
        self::assertCount(1, KeyScope::problems($this->allowed(prefix: 'camera'), self::BUCKET, 'other/'));
    }

    private function allowed(array $caps = ['listFiles', 'readFiles', 'shareFiles'], string $prefix = 'camera-footage/'): array
    {
        return ['buckets' => [['id' => self::BUCKET, 'name' => 'cams']], 'capabilities' => $caps, 'namePrefix' => $prefix];
    }
}
