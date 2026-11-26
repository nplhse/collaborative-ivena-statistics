<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use App\Import\Domain\Enum\ImportRunReasonCode;

/**
 * A failed import that still holds rows, or that replaced rows of older imports before #1551.
 */
final readonly class FailedImportRowsCandidate
{
    /**
     * @param list<int> $importsToReprocess older imports of the hospital whose rows this import may have replaced
     */
    public function __construct(
        public int $importId,
        public string $hospitalName,
        public ?string $periodStart,
        public ?string $periodEnd,
        public int $allocations,
        public int $closureIntervals,
        public int $projectionRows,
        public int $replacedRows,
        public bool $sourceFilePresent,
        public ?ImportRunReasonCode $lastReasonCode,
        public bool $hospitalLeavesStatistics,
        public array $importsToReprocess,
    ) {
    }

    public function action(): FailedImportRowsAction
    {
        if (0 === $this->allocations && 0 === $this->closureIntervals) {
            return FailedImportRowsAction::NoRows;
        }

        if (true === $this->lastReasonCode?->endsBeforeProcessing()) {
            return FailedImportRowsAction::KeepLastSuccessfulRun;
        }

        return $this->sourceFilePresent ? FailedImportRowsAction::Discard : FailedImportRowsAction::KeepSourceMissing;
    }
}
