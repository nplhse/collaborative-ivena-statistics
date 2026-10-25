<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

/**
 * Outcome of a bulk action on selected imports: how many were handled and why the others were skipped.
 */
final readonly class ImportBulkActionResult
{
    /**
     * @param array<string, int> $skipped    count per ImportBulkSkipReason value, only reasons that occurred
     * @param int|null           $batchRunId batch that groups the queued runs (bulk reprocess)
     */
    public function __construct(
        public int $handled,
        public array $skipped = [],
        public ?int $batchRunId = null,
    ) {
    }

    public function skippedCount(): int
    {
        return array_sum($this->skipped);
    }

    /**
     * @return list<array{reason: ImportBulkSkipReason, count: int}> in the order of ImportBulkSkipReason
     */
    public function skippedByReason(): array
    {
        $reasons = [];
        foreach (ImportBulkSkipReason::cases() as $reason) {
            $count = $this->skipped[$reason->value] ?? 0;
            if ($count > 0) {
                $reasons[] = ['reason' => $reason, 'count' => $count];
            }
        }

        return $reasons;
    }
}
