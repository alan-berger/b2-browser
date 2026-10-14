<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\PathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathPolicyTest extends TestCase
{
    private PathPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new PathPolicy('camera-footage/');
    }

    public function testAcceptsFilesInsideThePrefix(): void
    {
        $name = 'camera-footage/2026/10/14/000001/10-30-00.mp4';
        self::assertSame($name, $this->policy->file($name));
        self::assertSame('camera-footage/odd name #1 ?.mp4', $this->policy->file('camera-footage/odd name #1 ?.mp4'));
        self::assertSame('camera-footage/ünïcödé.jpg', $this->policy->file('camera-footage/ünïcödé.jpg'));
    }

    public static function rejectedFiles(): array
    {
        return [
            'outside prefix' => ['other/secret.mp4'],
            'sibling sharing the prefix text' => ['camera-footage-private/x.mp4'],
            'the prefix itself' => ['camera-footage/'],
            'folder' => ['camera-footage/2026/'],
            'leading slash' => ['/camera-footage/a.mp4'],
            'dot-dot segment' => ['camera-footage/../other/a.mp4'],
            'dot segment' => ['camera-footage/./a.mp4'],
            'empty segment' => ['camera-footage//a.mp4'],
            'null byte' => ["camera-footage/a\0.mp4"],
            'newline' => ["camera-footage/a\n.mp4"],
            'DEL' => ["camera-footage/a\x7F.mp4"],
            'invalid UTF-8' => ["camera-footage/\xC3\x28.mp4"],
            'over 1024 bytes' => ['camera-footage/' . str_repeat('a', 1020)],
            'empty' => [''],
            'array' => [['camera-footage/a.mp4']],
            'null' => [null],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function testRejectsFiles(mixed $name): void
    {
        self::assertNull($this->policy->file($name));
    }

    public function testFolderDefaultsToThePrefix(): void
    {
        self::assertSame('camera-footage/', $this->policy->folder(null));
        self::assertSame('camera-footage/', $this->policy->folder(''));
        self::assertSame('camera-footage/', $this->policy->folder('/'));
    }

    public function testFolderIsNormalisedWithTrailingSlash(): void
    {
        self::assertSame('camera-footage/2026/10/', $this->policy->folder('camera-footage/2026/10'));
        self::assertSame('camera-footage/2026/10/', $this->policy->folder('camera-footage/2026/10/'));
        self::assertSame('camera-footage/', $this->policy->folder('camera-footage'));
    }

    /** Regression: v1.x never applied the prefix to ?path=, exposing the whole bucket. */
    public function testFolderListingIsConfinedToThePrefix(): void
    {
        self::assertNull($this->policy->folder('other/'));
        self::assertNull($this->policy->folder('camera-footage-private/'));
        self::assertNull($this->policy->folder('camera-footage/../other'));
        self::assertNull($this->policy->folder(['x']));
    }

    public function testEmptyPrefixAllowsWholeBucket(): void
    {
        $open = new PathPolicy('');
        self::assertSame('', $open->folder(null));
        self::assertSame('any/where.mp4', $open->file('any/where.mp4'));
        self::assertNull($open->file('../x'));
    }

    public function testPrefixMustEndWithSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PathPolicy('camera-footage');
    }

    public function testRelative(): void
    {
        self::assertSame('2026/10/', $this->policy->relative('camera-footage/2026/10/'));
    }
}
