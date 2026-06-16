<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\FixtureVolume;

final readonly class ImportBatch
{
    public function __construct(
        public Hospital $hospital,
        public string $importName,
        public int $allocationCount,
    ) {
    }
}

final class ImportBatchPlanner
{
    /**
     * @param list<Hospital> $hospitals
     *
     * @return list<ImportBatch>
     */
    public function plan(FixtureVolume $volume, array $hospitals): array
    {
        $hospitals = array_values(array_filter(
            $hospitals,
            static fn (Hospital $hospital): bool => $hospital->isParticipating(),
        ));
        if ([] === $hospitals) {
            return [];
        }

        usort(
            $hospitals,
            static fn (Hospital $a, Hospital $b): int => strcmp((string) $a->getName(), (string) $b->getName()),
        );

        $importBudget = max(1, $volume->imports);
        $batches = [];

        for ($i = 0; $i < $importBudget; ++$i) {
            $hospital = $hospitals[$i % \count($hospitals)];
            $batches[] = new ImportBatch(
                hospital: $hospital,
                importName: sprintf(
                    '2025 Q%d IVENA allocations',
                    ($i % 4) + 1,
                ),
                allocationCount: 1,
            );
        }

        return $this->normalizeTotals($batches, $importBudget, $volume->allocations);
    }

    /**
     * @param list<ImportBatch> $batches
     *
     * @return list<ImportBatch>
     */
    private function normalizeTotals(array $batches, int $targetImports, int $targetAllocations): array
    {
        if ([] === $batches) {
            return [];
        }

        $batches = \array_slice($batches, 0, max(1, $targetImports));

        $currentAllocations = array_sum(array_map(static fn (ImportBatch $b): int => $b->allocationCount, $batches));
        if (0 === $currentAllocations) {
            return $batches;
        }

        $factor = $targetAllocations / $currentAllocations;

        return array_map(
            static fn (ImportBatch $batch): ImportBatch => new ImportBatch(
                hospital: $batch->hospital,
                importName: $batch->importName,
                allocationCount: max(1, (int) round((float) $batch->allocationCount * (float) $factor)),
            ),
            $batches,
        );
    }
}
