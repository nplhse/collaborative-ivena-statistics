<?php

namespace App\Service\Statistics;

use App\Model\PublicYearStatsView;
use App\Query\PublicYearStatsQuery;

final readonly class PublicYearStatsService
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private PublicYearStatsQuery $query,
    ) {
    }

    public function getYearViewModel(int $year): ?PublicYearStatsView
    {
        $raw = $this->query->fetch($year);
        if (null === $raw) {
            return null;
        }

        $total = $raw['total'];

        $pct = static function (int $value) use ($total): float {
            return $total > 0 ? round(100 * $value / $total, 1) : 0.0;
        };

        $computedAt = new \DateTimeImmutable((string) $raw['computed_at']);

        return new PublicYearStatsView(
            year: $year,

            total: $total,
            computedAt: $computedAt,

            genderM: $raw['gender_m'],
            genderW: $raw['gender_w'],
            genderD: $raw['gender_d'],
            malePct: $pct($raw['gender_m']),
            femalePct: $pct($raw['gender_w']),
            diversePct: $pct($raw['gender_d']),

            urg1: $raw['urg_1'],
            urg2: $raw['urg_2'],
            urg3: $raw['urg_3'],
            urg1Pct: $pct($raw['urg_1']),
            urg2Pct: $pct($raw['urg_2']),
            urg3Pct: $pct($raw['urg_3']),

            isVentilated: $raw['is_ventilated'],
            isVentilatedPct: $pct($raw['is_ventilated']),

            isCpr: $raw['is_cpr'],
            isCprPct: $pct($raw['is_cpr']),

            isShock: $raw['is_shock'],
            isShockPct: $pct($raw['is_shock']),

            withPhysician: $raw['with_physician'],
            withPhysicianPct: $pct($raw['with_physician']),

            isPregnant: $raw['is_pregnant'],
            isPregnantPct: $pct($raw['is_pregnant']),

            infectious: $raw['infectious'],
            infectiousPct: $pct($raw['infectious']),

            cathlabRequired: $raw['cathlab_required'],
            cathlabRequiredPct: $pct($raw['cathlab_required']),

            resusRequired: $raw['resus_required'],
            resusRequiredPct: $pct($raw['resus_required']),
        );
    }
}
