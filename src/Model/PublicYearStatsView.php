<?php

namespace App\Model;

/** @psalm-suppress PossiblyUnusedProperty */
final readonly class PublicYearStatsView
{
    public function __construct(
        public int $year,

        public int $total,
        public \DateTimeInterface $computedAt,

        public int $genderM,
        public int $genderW,
        public int $genderD,
        public float $malePct,
        public float $femalePct,
        public float $diversePct,

        public int $urg1,
        public int $urg2,
        public int $urg3,
        public float $urg1Pct,
        public float $urg2Pct,
        public float $urg3Pct,

        public int $isVentilated,
        public float $isVentilatedPct,

        public int $isCpr,
        public float $isCprPct,

        public int $isShock,
        public float $isShockPct,

        public int $withPhysician,
        public float $withPhysicianPct,

        public int $isPregnant,
        public float $isPregnantPct,

        public int $infectious,
        public float $infectiousPct,

        public int $cathlabRequired,
        public float $cathlabRequiredPct,

        public int $resusRequired,
        public float $resusRequiredPct,
    ) {
    }
}
