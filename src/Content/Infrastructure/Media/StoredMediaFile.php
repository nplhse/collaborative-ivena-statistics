<?php

declare(strict_types=1);

namespace App\Content\Infrastructure\Media;

/**
 * A file that {@see MediaStorage} has written: its storage key, size and SHA-256.
 */
final readonly class StoredMediaFile
{
    public function __construct(
        public string $storageKey,
        public int $size,
        public string $checksumSha256,
    ) {
    }
}
