<?php
declare(strict_types=1);

namespace B2Browser\Tests\Unit;

use B2Browser\Config;
use B2Browser\Pager;
use B2Browser\Session;
use B2Browser\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class PagerTest extends TestCase
{
    use TempDir;

    private Pager $pager;

    protected function setUp(): void
    {
        $_SESSION = [];
        $config = Config::fromArray([
            'b2' => ['key_id' => 'k', 'application_key' => 'a', 'bucket_name' => 'b', 'bucket_id' => 'i'],
            'auth' => ['allow_no_auth' => true],
            'storage_dir' => $this->makeTempDir(),
        ]);
        $this->pager = new Pager(new Session($config));
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $this->removeTempDir();
    }

    public function testWalksForwardAndBack(): void
    {
        self::assertSame(['page' => 1, 'start' => null], $this->pager->resolve('f/', null));
        $this->pager->recordNext('f/', 1, 'f/b');
        self::assertSame(['page' => 2, 'start' => 'f/b'], $this->pager->resolve('f/', '2'));
        $this->pager->recordNext('f/', 2, 'f/c');
        self::assertSame(['page' => 3, 'start' => 'f/c'], $this->pager->resolve('f/', '3'));
        self::assertSame(['page' => 2, 'start' => 'f/b'], $this->pager->resolve('f/', '2'));
    }

    public function testUnknownOrInvalidPagesFallBackToFirst(): void
    {
        foreach (['7', '0', '-1', 'abc', '1e3'] as $page) {
            self::assertSame(['page' => 1, 'start' => null], $this->pager->resolve('f/', $page));
        }
        self::assertSame(1, $this->pager->resolve('f/', ['2'])['page']);
    }

    public function testFoldersAreTrackedSeparately(): void
    {
        $this->pager->recordNext('a/', 1, 'a/x');
        self::assertSame(1, $this->pager->resolve('b/', '2')['page']);
    }

    public function testLastPageForgetsLaterPages(): void
    {
        $this->pager->recordNext('f/', 1, 'f/b');
        $this->pager->recordNext('f/', 2, 'f/c');
        $this->pager->recordNext('f/', 2, null); // folder shrank
        self::assertSame(1, $this->pager->resolve('f/', '3')['page']);
    }

    public function testBoundedNumberOfFolders(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->pager->recordNext("f{$i}/", 1, "f{$i}/x");
        }
        self::assertCount(20, $_SESSION['pager']);
        self::assertSame(2, $this->pager->resolve('f29/', '2')['page']);
        self::assertSame(1, $this->pager->resolve('f0/', '2')['page']);
    }
}
