<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

/**
 * What a scenario wrote for one hospital.
 */
final readonly class ScenarioHospitalResult
{
    public function __construct(
        public string $hospital,
        public int $importId,
        public int $allocations,
        public int $closures,
        public int $mciPatients,
    ) {
    }
}
