<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Scenario;

use App\Allocation\Domain\Entity\Hospital;
use App\DataFixtures\Generator\Application\PlannedClosure;

/**
 * What a scenario adds to one hospital.
 */
final readonly class ScenarioHospitalPlan
{
    /**
     * @param list<string>         $days        days of the scenario (Y-m-d)
     * @param array<string, int>   $dailyCounts extra allocations per day
     * @param list<PlannedClosure> $closures
     */
    public function __construct(
        public Hospital $hospital,
        public array $days,
        public array $dailyCounts,
        public array $closures,
        public int $mciPatients,
        public \DateTimeImmutable $mciStart,
        public int $mciWindowMinutes,
    ) {
    }

    public function lastDay(): string
    {
        $key = array_key_last($this->days);
        if (null === $key) {
            throw new \LogicException('A scenario has at least one day.');
        }

        return $this->days[$key];
    }

    public function isMci(): bool
    {
        return $this->mciPatients > 0;
    }

    public function allocationCount(): int
    {
        return $this->isMci() ? 0 : array_sum($this->dailyCounts);
    }
}
