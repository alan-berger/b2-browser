<?php
declare(strict_types=1);

namespace B2Browser\Tests;

/** Per-test scratch directory, removed afterwards. */
trait TempDir
{
    private string $tmp;

    protected function makeTempDir(): string
    {
        $this->tmp = sys_get_temp_dir() . '/b2b-test-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0700, true);
        return $this->tmp;
    }

    protected function removeTempDir(): void
    {
        if (!isset($this->tmp) || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tmp);
    }
}
