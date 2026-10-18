<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\B2\B2Client;
use B2Browser\Http;
use B2Browser\MediaType;
use B2Browser\View;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    public function testContentDispositionIsRfc6266(): void
    {
        self::assertSame(
            'attachment; filename="10-30-00.mp4"; filename*=UTF-8\'\'10-30-00.mp4',
            Http::contentDisposition('camera-footage/2026/10/14/000001/10-30-00.mp4'),
        );
        // Quotes cannot break out of the quoted-string, and non-ASCII survives in filename*.
        self::assertSame(
            'attachment; filename="a_b_ _.mp4"; filename*=UTF-8\'\'a%22b%5C%20%C3%A9.mp4',
            Http::contentDisposition('x/a"b\\ é.mp4'),
        );
    }

    public function testObjectPathsArePercentEncodedPerSegment(): void
    {
        self::assertSame('camera-footage/2026/a%20b%23c%3Fd.mp4', B2Client::encodePath('camera-footage/2026/a b#c?d.mp4'));
        self::assertSame('x/%C3%A9', B2Client::encodePath('x/é'));
    }

    public function testMediaClassification(): void
    {
        $media = new MediaType(['mp4', '.MOV'], ['jpg', 'jpeg']);
        self::assertSame('video', $media->classify('a/B.MP4'));
        self::assertSame('video', $media->classify('a/b.mov'));
        self::assertSame('image', $media->classify('a/b.JPEG'));
        self::assertSame('folder', $media->classify('a/b/'));
        self::assertSame('other', $media->classify('a/b.txt'));
        self::assertSame('video/webm', MediaType::videoMime('x.webm'));
        self::assertSame('video/mp4', MediaType::videoMime('x.m4v'));
    }

    public function testEscapingAndBytes(): void
    {
        self::assertSame('&lt;img src=x onerror=&quot;a&quot;&gt;&apos;', View::escape('<img src=x onerror="a">\''));
        self::assertSame('1.50 KB', View::bytes(1536));
        self::assertSame('512 B', View::bytes(512));
        self::assertSame('2.00 GB', View::bytes(2 * 1073741824));
    }
}
