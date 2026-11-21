<?php

declare(strict_types=1);

namespace App\Content\Application\Media;

/**
 * What one run of the media integrity check found and did (ADR 039, "Integrity check and cleanup").
 */
final readonly class MediaIntegrityReport
{
    public const string BLOCKED_EMPTY_LIBRARY = 'empty_library';
    public const string BLOCKED_ORPHAN_SHARE = 'orphan_share';
    public const string BLOCKED_DRY_RUN = 'dry_run';

    /**
     * @param list<string> $missing        storage keys of media items whose file does not exist
     * @param list<string> $damaged        storage keys whose file differs in size or SHA-256 from the media item
     * @param list<string> $orphansDeleted paths below the storage root that no media item refers to and were deleted
     * @param list<string> $orphansKept    such paths that were kept: younger than the grace period, or deletion blocked
     * @param list<string> $unexpected     paths outside the storage layout, reported only
     */
    public function __construct(
        public \DateTimeImmutable $checkedAt,
        public bool $full,
        public int $files,
        public int $bytes,
        public int $hashed,
        public array $missing,
        public array $damaged,
        public array $orphansDeleted,
        public array $orphansKept,
        public int $leftoversDeleted,
        public array $unexpected,
        public ?string $deletionBlockedReason,
    ) {
    }

    public function hasProblems(): bool
    {
        return [] !== $this->missing || [] !== $this->damaged;
    }
}
