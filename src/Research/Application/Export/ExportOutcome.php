<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * Result of running all queries of a release (ADR 045): the files, whether a group fell below the minimum of
 * hospitals, or the technical error that stopped the run.
 */
final readonly class ExportOutcome
{
    /**
     * @param list<ExportFile>     $files
     * @param array<string, mixed> $settings the effective limits, timeout and isolation, for the internal record
     */
    public function __construct(
        public array $files,
        public array $settings,
        public int $durationMs,
        public ?string $error = null,
    ) {
    }

    public function isBlocked(): bool
    {
        if (null !== $this->error) {
            return false;
        }

        return array_any($this->files, fn (ExportFile $file): bool => $file->belowMinimum > 0);
    }
}
