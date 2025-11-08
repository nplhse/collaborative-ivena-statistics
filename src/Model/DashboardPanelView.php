<?php

declare(strict_types=1);

namespace App\Model;

/** @psalm-suppress PossiblyUnusedProperty */
final readonly class DashboardPanelView
{
    public function __construct(
        public Scope $scope,
        public int $total,
        public \DateTimeInterface $computedAt,

        public int $genderM,
        public int $genderW,
        public int $genderD,
        public int $genderU,

        public int $urg1,
        public int $urg2,
        public int $urg3,

        public int $cathlabRequired,
        public int $resusRequired,

        public int $isCpr,
        public int $isVentilated,
        public int $isShock,
        public int $isPregnant,
        public int $withPhysician,
        public int $infectious,

        public float $pctMale,
        public float $pctFemale,
        public float $pctDiverse,
        public float $pctVentilated,
        public float $pctCpr,
        public float $pctShock,
        public float $pctPregnant,
        public float $pctWithPhysician,
        public float $pctInfectious,
        public float $pctUrg1,
        public float $pctUrg2,
        public float $pctUrg3,
        public float $pctCathlabRequired,
        public float $pctResusRequired,
    ) {
    }
}
