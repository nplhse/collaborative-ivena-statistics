<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

use App\Statistics\ClosureAnalytics\Application\ClosureEventAssignmentPopulation;

enum ClosureVolumeSeriesScope: string
{
    case Department = 'department';
    case Speciality = 'speciality';

    public function sqlValue(): string
    {
        return $this->value;
    }

    public function assignmentPopulation(): ClosureEventAssignmentPopulation
    {
        return match ($this) {
            self::Speciality => ClosureEventAssignmentPopulation::Speciality,
            self::Department => ClosureEventAssignmentPopulation::Closed,
        };
    }
}
