<?php

declare(strict_types=1);

namespace App\Import\Application\Service;

use App\Import\Application\Contracts\ImportCoverageReaderInterface;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\Domain\ValueObject\ImportDataCoverage;

/**
 * Keeps `import.data_coverage` in line with the imported rows. Runs next to the export
 * period derivation, before deduplication, so the summary describes the imported file.
 */
final readonly class ImportDataCoverageCalculator
{
    public function __construct(private ImportCoverageReaderInterface $reader)
    {
    }

    public function calculate(Import $import): ?ImportDataCoverage
    {
        $importId = $import->getId();
        if (null === $importId) {
            return null;
        }

        return match ($import->getType()) {
            ImportType::CLOSURE => $this->reader->closureCoverage($importId),
            ImportType::ALLOCATION => $this->reader->allocationCoverage($importId),
            null => null,
        };
    }

    /**
     * Calculates and stores the summary on the entity. The caller flushes.
     */
    public function apply(Import $import): void
    {
        $import->applyDataCoverage($this->calculate($import));
    }
}
