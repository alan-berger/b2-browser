<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\PathInfo;
use PHPUnit\Framework\TestCase;

final class PathInfoTest extends TestCase
{
    public function testExtractsDateAndCameraRegardlessOfPrefixDepth(): void
    {
        foreach (['camera-footage/', 'a/b/c/', ''] as $prefix) {
            $info = PathInfo::parse($prefix . '2026/01/15/000002/10-30-00.MP4');
            self::assertSame('2026-01-15', $info->date);
            self::assertSame('000002', $info->camera);
            self::assertSame('10-30-00.MP4', $info->fileName);
            self::assertSame('mp4', $info->extension);
        }
    }

    public function testFileDirectlyInDayFolderHasNoCamera(): void
    {
        $info = PathInfo::parse('cams/2026/01/15/10-30-00.jpg');
        self::assertSame('2026-01-15', $info->date);
        self::assertNull($info->camera);
    }

    public function testFolders(): void
    {
        self::assertSame('000001', PathInfo::parse('cams/2026/01/15/000001/')->camera);
        self::assertNull(PathInfo::parse('cams/2026/01/15/')->camera);
        self::assertNull(PathInfo::parse('cams/2026/01/')->date);
    }

    public function testRejectsImpossibleDates(): void
    {
        self::assertNull(PathInfo::parse('cams/2026/02/30/cam/x.mp4')->date);
        self::assertNull(PathInfo::parse('cams/2026/13/01/cam/x.mp4')->date);
        self::assertNull(PathInfo::parse('random/file.mp4')->date);
    }

    public function testFolderForDate(): void
    {
        self::assertSame('2026/10/14/', PathInfo::folderForDate('2026-10-14', '{Y}/{m}/{d}/'));
        self::assertSame('day-20261014/', PathInfo::folderForDate('2026-10-14', '/day-{Y}{m}{d}'));
        self::assertNull(PathInfo::folderForDate('2026-02-30', '{Y}/{m}/{d}/'));
        self::assertNull(PathInfo::folderForDate('14/10/2026', '{Y}/{m}/{d}/'));
        self::assertNull(PathInfo::folderForDate('2026-10-14', ''));
    }
}
