<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\Infrastructure\Query\Dto;

final readonly class ClosedDepartmentMetricsRow
{
    public function __construct(
        public int $totalCount,
        public int $closedCount,
        public int $regularCount,
        public int $closedDepartmentCount,
        public int $totalDepartmentCount,
        public int $closedSk1,
        public int $regularSk1,
        public int $closedSk2,
        public int $regularSk2,
        public int $closedSk3,
        public int $regularSk3,
        public int $closedMale,
        public int $regularMale,
        public int $closedFemale,
        public int $regularFemale,
        public int $closedOther,
        public int $regularOther,
        public int $closedWithPhysician,
        public int $regularWithPhysician,
        public int $closedResus,
        public int $regularResus,
        public int $closedCathlab,
        public int $regularCathlab,
        public int $closedCpr,
        public int $regularCpr,
        public int $closedVentilated,
        public int $regularVentilated,
        public int $closedShock,
        public int $regularShock,
        public int $closedPregnant,
        public int $regularPregnant,
        public ?float $closedMeanTransportMinutes,
        public ?float $regularMeanTransportMinutes,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            null, null,
        );
    }

    public static function fromKpis(
        int $totalCount,
        int $closedCount,
        int $closedDepartmentCount,
        int $totalDepartmentCount,
        ?float $closedMeanTransportMinutes,
    ): self {
        return new self(
            $totalCount,
            $closedCount,
            0,
            $closedDepartmentCount,
            $totalDepartmentCount,
            0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0,
            0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0,
            $closedMeanTransportMinutes,
            null,
        );
    }
}
