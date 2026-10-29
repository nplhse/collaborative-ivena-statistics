<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Run;

/**
 * One hospital of a run as stored.
 */
final readonly class GenerationRunHospitalView
{
    /**
     * @param list<int> $allocationImportIds
     * @param list<int> $closureImportIds
     */
    public function __construct(
        public int $hospitalId,
        public string $hospitalName,
        public ?int $ownerId,
        public GenerationRunHospitalStatus $status,
        public int $months,
        public int $allocations,
        public int $closures,
        public int $mciCases,
        public array $allocationImportIds,
        public array $closureImportIds,
        public int $projectionAttempts,
        public ?string $reasonCode,
        public ?string $error,
        public ?\DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $generatedAt,
        public ?\DateTimeImmutable $finishedAt,
        /** Step of the asynchronous stage while projecting (SyntheticDataPipeline::STEP_*), with done of total. */
        public ?string $projectionStep = null,
        public int $projectionDone = 0,
        public int $projectionTotal = 0,
    ) {
    }
}
