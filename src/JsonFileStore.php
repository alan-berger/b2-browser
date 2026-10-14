<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * A small JSON document on disk, mutated under an exclusive flock() so that
 * every read-modify-write is atomic with respect to other PHP workers.
 *
 * Failures throw StorageException; callers making security decisions let it
 * propagate so the request fails closed.
 */
final class JsonFileStore
{
    public function __construct(private readonly string $path)
    {
    }

    /**
     * Run $fn with the current contents and persist whatever it returns as the
     * first element of a [newData, result] pair. Returning null as newData
     * leaves the file untouched.
     *
     * @template T
     * @param callable(array<mixed>): array{0: array<mixed>|null, 1: T} $fn
     * @return T
     */
    public function transact(callable $fn): mixed
    {
        $existed = is_file($this->path);
        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            throw new StorageException("Cannot open state file {$this->path}");
        }
        if (!$existed) {
            @chmod($this->path, 0600);
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                throw new StorageException("Cannot lock state file {$this->path}");
            }
            $raw = stream_get_contents($fh);
            $data = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                // A corrupt file is reset rather than trusted.
                $data = is_array($decoded) ? $decoded : [];
            }

            [$newData, $result] = $fn($data);

            if ($newData !== null) {
                $json = json_encode($newData, JSON_UNESCAPED_SLASHES);
                if ($json === false || !ftruncate($fh, 0) || !rewind($fh)
                    || fwrite($fh, $json) !== strlen($json) || !fflush($fh)) {
                    throw new StorageException("Cannot write state file {$this->path}");
                }
            }
            return $result;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @return array<mixed> */
    public function read(): array
    {
        return $this->transact(static fn(array $data): array => [null, $data]);
    }
}
