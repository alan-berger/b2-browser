<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Page-number navigation over B2's forward-only cursor (nextFileName).
 *
 * The start name for each page already visited is remembered in the session
 * per folder, so the UI can offer Previous as well as Next and page numbers
 * appear in the URL instead of raw object names. A page that was never
 * reached in this session falls back to page 1.
 */
final class Pager
{
    private const SESSION_KEY = 'pager';
    private const MAX_FOLDERS = 20;

    public function __construct(private readonly Session $session)
    {
    }

    /** @return array{page: int, start: ?string} */
    public function resolve(string $folder, mixed $requestedPage): array
    {
        $page = is_string($requestedPage) && ctype_digit($requestedPage) ? (int) $requestedPage : 1;
        $starts = $this->starts($folder);
        if ($page < 1 || !array_key_exists($page, $starts)) {
            $page = 1;
        }
        return ['page' => $page, 'start' => $starts[$page]];
    }

    /** Remember where the page after $page begins. */
    public function recordNext(string $folder, int $page, ?string $nextFileName): void
    {
        $all = $this->all();
        $key = hash('sha256', $folder);
        $starts = $all[$key] ?? [1 => null];
        if ($nextFileName !== null) {
            $starts[$page + 1] = $nextFileName;
        } else {
            // This is the last page; forget any stale later pages.
            $starts = array_filter($starts, static fn(int $p): bool => $p <= $page, ARRAY_FILTER_USE_KEY);
        }
        unset($all[$key]);
        $all[$key] = $starts; // most recently used last
        if (count($all) > self::MAX_FOLDERS) {
            $all = array_slice($all, -self::MAX_FOLDERS, null, true);
        }
        $this->session->set(self::SESSION_KEY, $all);
    }

    /** @return array<int, ?string> */
    private function starts(string $folder): array
    {
        $all = $this->all();
        $starts = $all[hash('sha256', $folder)] ?? [];
        $starts[1] = null;
        return $starts;
    }

    /** @return array<string, array<int, ?string>> */
    private function all(): array
    {
        $value = $this->session->get(self::SESSION_KEY);
        return is_array($value) ? $value : [];
    }
}
