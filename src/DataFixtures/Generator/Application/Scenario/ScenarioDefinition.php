<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

/**
 * An event that is laid over existing data (ADR 022).
 *
 * Extra cases draw their indication from `indications`; department, age, urgency and flags follow
 * from the indication as for base data. A mass casualty incident writes MCI cases instead of
 * allocations: urgency 1, resuscitation room and physician, within a short window.
 */
final readonly class ScenarioDefinition
{
    /**
     * @param array<int|string, float> $indications                PZC code → share of the extra cases
     * @param float                    $extraShare                 extra allocations per day as a share of the hospital's daily rate
     * @param list<string>             $closedDepartments          departments that get extra closures
     * @param float                    $closuresPerHospitalAndWeek extra closures per hospital and week
     * @param array{int, int}          $days                       duration in days (min, max)
     * @param array{int, int}          $mciPatients                MCI patients of the whole dispatch area (min, max); [0, 0] for none
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $days,
        public array $indications,
        public float $extraShare = 0.0,
        public array $closedDepartments = [],
        public float $closuresPerHospitalAndWeek = 0.0,
        public array $mciPatients = [0, 0],
    ) {
    }

    public function isMci(): bool
    {
        return $this->mciPatients[1] > 0;
    }
}
