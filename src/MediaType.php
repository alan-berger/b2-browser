<?php
declare(strict_types=1);

namespace B2Browser;

/** Classifies objects by extension using the configured (case-insensitive) lists. */
final class MediaType
{
    /** @var array<string, true> */
    private readonly array $video;
    /** @var array<string, true> */
    private readonly array $image;

    /**
     * @param list<string> $videoExtensions
     * @param list<string> $imageExtensions
     */
    public function __construct(array $videoExtensions, array $imageExtensions)
    {
        $normalise = static fn(array $list): array =>
            array_fill_keys(array_map(static fn($e) => strtolower(ltrim((string) $e, '.')), $list), true);
        $this->video = $normalise($videoExtensions);
        $this->image = $normalise($imageExtensions);
    }

    /** 'folder', 'video', 'image' or 'other'. */
    public function classify(string $name): string
    {
        if (str_ends_with($name, '/')) {
            return 'folder';
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return match (true) {
            isset($this->video[$ext]) => 'video',
            isset($this->image[$ext]) => 'image',
            default => 'other',
        };
    }

    /** MIME type to declare on a <source> element; browsers sniff the rest. */
    public static function videoMime(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            default => 'video/mp4',
        };
    }
}
