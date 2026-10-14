<?php
declare(strict_types=1);

namespace B2Browser;

/**
 * Best-effort metadata from an object name laid out as …/YYYY/MM/DD/<camera>/<file>.
 *
 * v1.x assumed fixed positions (parts[1..4]), which broke as soon as the
 * prefix had more or fewer levels. This locates the first YYYY/MM/DD run
 * anywhere in the path instead; the segment after the day (if it is a folder)
 * is taken as the camera.
 */
final class PathInfo
{
    private function __construct(
        public readonly ?string $date,
        public readonly ?string $camera,
        public readonly string $fileName,
        public readonly string $extension,
    ) {
    }

    public static function parse(string $name): self
    {
        $isFolder = str_ends_with($name, '/');
        $parts = explode('/', rtrim($name, '/'));
        $fileName = $isFolder ? '' : (string) end($parts);
        $extension = $isFolder ? '' : strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $date = null;
        $camera = null;
        $count = count($parts);
        for ($i = 0; $i + 2 < $count; $i++) {
            if (preg_match('/^\d{4}$/', $parts[$i])
                && preg_match('/^(0[1-9]|1[0-2])$/', $parts[$i + 1])
                && preg_match('/^(0[1-9]|[12]\d|3[01])$/', $parts[$i + 2])
                && checkdate((int) $parts[$i + 1], (int) $parts[$i + 2], (int) $parts[$i])) {
                $date = "{$parts[$i]}-{$parts[$i + 1]}-{$parts[$i + 2]}";
                // The camera folder sits after the day and before the file.
                $cameraIndex = $i + 3;
                $lastFolderIndex = $isFolder ? $count - 1 : $count - 2;
                if ($cameraIndex <= $lastFolderIndex) {
                    $camera = $parts[$cameraIndex];
                }
                break;
            }
        }

        return new self($date, $camera, $fileName, $extension);
    }

    /**
     * Build the folder for a date from a template such as '{Y}/{m}/{d}/'.
     * Returns null for an invalid date string (expects YYYY-MM-DD).
     */
    public static function folderForDate(string $date, string $template): ?string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date || $template === '') {
            return null;
        }
        $path = strtr($template, ['{Y}' => $dt->format('Y'), '{m}' => $dt->format('m'), '{d}' => $dt->format('d')]);
        return rtrim(ltrim($path, '/'), '/') . '/';
    }
}
