<?php
declare(strict_types=1);

namespace B2Browser\B2;

/**
 * Checks that the B2 application key is no more powerful than this app needs.
 *
 * A restricted key is the control that still holds if the application has a
 * bug: B2 itself refuses to list or serve anything outside the key's bucket
 * and namePrefix, and a leaked key cannot modify or delete footage.
 */
final class KeyScope
{
    public const REQUIRED = ['listFiles', 'readFiles', 'shareFiles'];

    /**
     * @param array<string, mixed> $allowed The `allowed` block from b2_authorize_account.
     * @return list<string> Human-readable problems; empty if the key is acceptable.
     */
    public static function problems(array $allowed, string $bucketId, string $prefix): array
    {
        $problems = [];
        $capabilities = array_map('strval', (array) ($allowed['capabilities'] ?? []));

        $missing = array_diff(self::REQUIRED, $capabilities);
        if ($missing !== []) {
            $problems[] = 'key lacks required capabilities: ' . implode(', ', $missing);
        }
        // Read-only keys made in the B2 web UI also carry harmless list/read
        // capabilities (listBuckets, readBucketEncryption, …); only anything
        // that can change or delete data is rejected.
        $dangerous = array_values(array_filter(
            $capabilities,
            static fn(string $c): bool => (bool) preg_match('/^(write|delete)|^bypassGovernance$/', $c),
        ));
        if ($dangerous !== []) {
            $problems[] = 'key can modify or delete data (' . implode(', ', $dangerous) . '); use a read-only key';
        }

        $bucketIds = [];
        foreach ((array) ($allowed['buckets'] ?? []) as $bucket) {
            if (is_array($bucket) && isset($bucket['id'])) {
                $bucketIds[] = (string) $bucket['id'];
            }
        }
        if ($bucketIds === []) {
            $problems[] = 'key is not restricted to a bucket';
        } elseif ($bucketIds !== [$bucketId]) {
            $problems[] = 'key must be restricted to exactly the configured bucket';
        }

        $namePrefix = $allowed['namePrefix'] ?? null;
        if (!is_string($namePrefix) || $namePrefix === '') {
            $problems[] = 'key has no namePrefix restriction';
        } elseif (!str_starts_with($prefix, $namePrefix)) {
            $problems[] = "configured prefix must lie within the key's namePrefix ({$namePrefix})";
        }

        return $problems;
    }
}
