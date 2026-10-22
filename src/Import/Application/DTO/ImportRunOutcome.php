<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportStatus;

/**
 * Terminal result of one run: status, counts and, for failed runs, a reason code.
 */
final readonly class ImportRunOutcome
{
    public function __construct(
        public ImportRunStatus $status,
        public ?int $rowsTotal = null,
        public ?int $rowsOk = null,
        public ?int $rowsRejected = null,
        public ?ImportRunReasonCode $reasonCode = null,
    ) {
    }

    public static function failed(ImportRunReasonCode $reasonCode): self
    {
        return new self(ImportRunStatus::Failed, reasonCode: $reasonCode);
    }

    /**
     * Builds the outcome from an import that ImportEvaluation has just evaluated.
     */
    public static function fromEvaluatedImport(Import $import): self
    {
        $importStatus = $import->getStatus();
        if (!$importStatus instanceof ImportStatus) {
            throw new \LogicException('Evaluated import has no status.');
        }

        $status = ImportRunStatus::fromImportStatus($importStatus);
        $total = $import->getRowCount() ?? 0;

        $reasonCode = null;
        if (ImportRunStatus::Failed === $status) {
            $reasonCode = 0 === $total ? ImportRunReasonCode::NoRows : ImportRunReasonCode::RejectionRatioExceeded;
        }

        return new self(
            status: $status,
            rowsTotal: $total,
            rowsOk: $import->getRowsPassed() ?? 0,
            rowsRejected: $import->getRowsRejected() ?? 0,
            reasonCode: $reasonCode,
        );
    }
}
