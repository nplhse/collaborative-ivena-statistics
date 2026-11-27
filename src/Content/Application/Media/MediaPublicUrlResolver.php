<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

use App\Content\Domain\Entity\Media;

/**
 * Public URL of a media file and the way back from a URL found in content (ADR 039).
 *
 * A stored file `originals/<YYYY>/<MM>/<name>` is served at `/media/<YYYY>/<MM>/<name>`.
 */
final readonly class MediaPublicUrlResolver
{
    public const string URI_PREFIX = '/media/';

    private const string ORIGINALS_PREFIX = 'originals/';
    private const string STORED_PATH_PATTERN = '#^(\d{4})/(\d{2})/([0-9a-f-]{36}\.[a-z0-9]{1,8})$#';

    public function resolve(Media $media): string
    {
        $storageKey = $media->getStorageKey();
        if (null !== $storageKey && str_starts_with($storageKey, self::ORIGINALS_PREFIX)) {
            return self::URI_PREFIX.substr($storageKey, \strlen(self::ORIGINALS_PREFIX));
        }

        return '';
    }

    /** Storage key of a URL of the form `/media/<YYYY>/<MM>/<name>`, or null for any other URL. */
    public function storageKeyFromUrl(string $url): ?string
    {
        $path = $this->path($url) ?? '';
        if (!str_starts_with($path, self::URI_PREFIX)) {
            return null;
        }

        return self::storageKeyFromPath(substr($path, \strlen(self::URI_PREFIX)));
    }

    /** Storage key of the part after `/media/`, for example `2026/11/<name>`. */
    public static function storageKeyFromPath(string $path): ?string
    {
        return 1 === preg_match(self::STORED_PATH_PATTERN, $path) ? self::ORIGINALS_PREFIX.$path : null;
    }

    /** Path of a URL that is relative to this site; absolute URLs of other sites give null. */
    private function path(string $url): ?string
    {
        $url = trim($url);
        if ('' === $url || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return null;
        }

        $path = parse_url($url, \PHP_URL_PATH);

        return \is_string($path) ? $path : null;
    }
}
