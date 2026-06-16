<?php

declare(strict_types=1);

namespace App\DataFixtures\Allocation;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalSize;
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
        if ([] === $hospitals) {
            return [];
        }

        /** @var array<string, list<Hospital>> $bySize */
        $bySize = [
            HospitalSize::LARGE->value => [],
            HospitalSize::MEDIUM->value => [],
            HospitalSize::SMALL->value => [],
        ];

        foreach ($hospitals as $hospital) {
            $size = ($hospital->getSize() ?? HospitalSize::MEDIUM)->value;
            $bySize[$size][] = $hospital;
        }

        $batches = [];
        $importBudget = $volume->imports;
        $allocationBudget = $volume->allocations;

        /** @var array<string, float> $sizeWeights */
        $sizeWeights = [
            HospitalSize::LARGE->value => 0.70,
            HospitalSize::MEDIUM->value => 0.20,
            HospitalSize::SMALL->value => 0.10,
        ];

        $importsPerSize = [
            HospitalSize::LARGE->value => 2,
            HospitalSize::MEDIUM->value => 1,
            HospitalSize::SMALL->value => 1,
        ];

        foreach ($bySize as $size => $sizeHospitals) {
            if ([] === $sizeHospitals) {
                continue;
            }

            $weight = $sizeWeights[$size];
            $sizeAllocationBudget = (int) round((float) $allocationBudget * $weight);
            $importsForSize = min(
                \count($sizeHospitals) * $importsPerSize[$size],
                max(1, (int) round((float) $importBudget * $weight)),
            );
            $perImport = max(1, (int) round($sizeAllocationBudget / max(1, $importsForSize)));

            $created = 0;
            foreach ($sizeHospitals as $hospital) {
                $importsForHospital = $importsPerSize[$size];
                for ($i = 0; $i < $importsForHospital && $created < $importsForSize; ++$i, ++$created) {
                    $batches[] = new ImportBatch(
                        hospital: $hospital,
                        importName: sprintf(
                            '2025 Q%d IVENA allocations — %s',
                            ($i % 4) + 1,
                            (string) $hospital->getName(),
                        ),
                        allocationCount: $perImport,
                    );
                }
            }
        }

        return $this->normalizeTotals($batches, $volume->imports, $volume->allocations);
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
