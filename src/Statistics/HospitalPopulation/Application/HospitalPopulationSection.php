<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\Application;

enum HospitalPopulationSection: string
{
    case Participation = 'participation';
    case Coverage = 'coverage';
    case Beds = 'beds';
    case Allocations = 'allocations';

    public function labelKey(): string
    {
        return match ($this) {
            self::Participation => 'stats.hospital_population.tab.participation',
            self::Coverage => 'stats.hospital_population.tab.coverage',
            self::Beds => 'stats.hospital_population.nav.beds',
            self::Allocations => 'stats.hospital_population.nav.allocations',
        };
    }

    public function pretitleKey(): string
    {
        return match ($this) {
            self::Participation => 'stats.hospital_population.pretitle.participation',
            self::Coverage => 'stats.hospital_population.pretitle.coverage',
            self::Beds => 'stats.hospital_population.pretitle.beds',
            self::Allocations => 'stats.hospital_population.pretitle.allocations',
        };
    }
}
