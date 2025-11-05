<?php

declare(strict_types=1);

namespace App\Model;

/** @psalm-suppress PossiblyUnusedProperty */
final class CohortSumsView
{
    public function __construct(
        public Scope $scope,
        public int $total,
        public \DateTimeImmutable $computedAt,
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
    ) {
    }
}
