<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/**
 * Outcome of moving media items from the old upload directory into the media storage (ADR 039).
 */
final readonly class MediaStorageMigrationResult
{
    /**
     * @param list<string> $missing  old file names of media items whose file is not in the old directory
     * @param list<string> $failed   old file names that could not be moved, with the reason
     * @param list<string> $unlinked files in the old directory that no media item refers to
     */
    public function __construct(
        public int $moved,
        public int $movedBytes,
        public array $missing,
        public array $failed,
        public array $unlinked,
    ) {
    }

    public function hasFailures(): bool
    {
        return [] !== $this->failed;
    }
}
