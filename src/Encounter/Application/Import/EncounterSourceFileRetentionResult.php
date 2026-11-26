<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

/**
 * Outcome of one pass of the encounter upload file retention (#1049). Import ids only, never file names
 * or content.
 */
final readonly class EncounterSourceFileRetentionResult
{
    /**
     * @param list<int> $deleted import ids whose file was deleted in this pass (or would be, in a dry run)
     * @param list<int> $missing import ids whose file was already gone; they are marked as deleted
     * @param list<int> $failed  import ids whose file could not be deleted; the next pass tries again
     */
    public function __construct(
        public \DateTimeImmutable $cutoff,
        public array $deleted,
        public array $missing,
        public array $failed,
    ) {
    }

    /**
     * Counters for the job run (ADR 019).
     *
     * @return array<string, int>
     */
    public function metrics(): array
    {
        return [
            'deleted_files' => \count($this->deleted),
            'missing_files' => \count($this->missing),
            'failed_deletions' => \count($this->failed),
        ];
    }
}
