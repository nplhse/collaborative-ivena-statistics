<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

final readonly class CoverageCalculator
{
    public function calculate(int $participants, int $population): float
    {
        if ($population <= 0) {
            return 0.0;
        }

        return $participants / $population;
    }

    public function calculatePercent(int $participants, int $population): float
    {
        return $this->calculate($participants, $population) * 100.0;
    }
}
