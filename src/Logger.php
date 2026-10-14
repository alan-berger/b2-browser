<?php
declare(strict_types=1);

namespace B2Browser;

/** Append-only JSON-lines activity log with size-based rotation. */
final class Logger
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $path,
        private readonly int $maxBytes = 10485760,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function event(string $event, string $ip, ?string $user, array $context = []): void
    {
        $this->write([
            'timestamp' => date('c'),
            'event' => $event,
            'username' => $user ?? 'N/A',
            'ip' => $ip,
            'details' => $context,
        ]);
    }

    /** Internal errors go to the PHP error log as well as the activity log. */
    public function error(string $message, string $ip = ''): void
    {
        error_log('b2-browser: ' . $message);
        $this->write(['timestamp' => date('c'), 'event' => 'ERROR', 'ip' => $ip, 'details' => ['message' => $message]]);
    }

    /** @param array<string, mixed> $entry */
    private function write(array $entry): void
    {
        if (!$this->enabled || $this->path === '') {
            return;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log("b2-browser: cannot create log directory {$dir}");
            return;
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        $isNew = !is_file($this->path);
        $fh = @fopen($this->path, 'a');
        if ($fh === false) {
            error_log("b2-browser: cannot open log file {$this->path}");
            return;
        }
        if ($isNew) {
            @chmod($this->path, 0640);
        }
        flock($fh, LOCK_EX);
        fwrite($fh, $line);
        fflush($fh);
        clearstatcache(true, $this->path);
        if (filesize($this->path) > $this->maxBytes) {
            // Rotate while still holding the lock; writers that opened the old
            // inode finish their line in the rotated file.
            @rename($this->path, $this->path . '.' . date('Ymd-His') . '.old');
        }
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
