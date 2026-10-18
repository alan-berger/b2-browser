<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Validates object names and folder paths taken from the request and confines
 * them to the configured prefix.
 *
 * Fixes over v1.x:
 *  - Applied to folder listing (?path=), not only to play/download, so the
 *    rest of the bucket can no longer be enumerated.
 *  - The prefix always ends in '/', so 'camera-footage' no longer also
 *    matches 'camera-footage-private/...'.
 *  - Rejects control characters, invalid UTF-8, empty or dot segments, and
 *    names over B2's 1024-byte limit.
 *
 * The B2 application key's namePrefix enforces the same boundary server-side;
 * this class is the application-level layer of that defence.
 */
final class PathPolicy
{
    private const MAX_BYTES = 1024;

    public function __construct(private readonly string $prefix)
    {
        if ($prefix !== '' && !str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException('Prefix must be empty or end with "/"');
        }
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /** A file (object) name, or null if it is not acceptable. */
    public function file(mixed $name): ?string
    {
        if (!is_string($name) || str_ends_with($name, '/')) {
            return null;
        }
        $name = $this->wellFormed($name);
        if ($name === null || $name === '' || !$this->insidePrefix($name)) {
            return null;
        }
        return $name;
    }

    /**
     * A folder path ending in '/'. An empty or missing value means the root,
     * which is the prefix itself. Returns null if the value is not acceptable.
     */
    public function folder(mixed $path): ?string
    {
        if ($path === null || $path === '') {
            return $this->prefix;
        }
        if (!is_string($path)) {
            return null;
        }
        $path = rtrim($path, '/') . '/';
        if ($path === '/') {
            return $this->prefix;
        }
        $path = $this->wellFormed($path);
        if ($path === null || !$this->insidePrefix($path)) {
            return null;
        }
        return $path;
    }

    /** True if $name lies within the prefix (or is the prefix folder itself). */
    public function insidePrefix(string $name): bool
    {
        return $this->prefix === '' || str_starts_with($name, $this->prefix);
    }

    /** The part of a path below the prefix, for display. */
    public function relative(string $name): string
    {
        return $this->prefix !== '' && str_starts_with($name, $this->prefix)
            ? substr($name, strlen($this->prefix))
            : $name;
    }

    private function wellFormed(string $name): ?string
    {
        if (strlen($name) > self::MAX_BYTES
            || !mb_check_encoding($name, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $name)
            || str_starts_with($name, '/')) {
            return null;
        }
        $segments = explode('/', rtrim($name, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        return $name;
    }
}
