<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\DashboardPanelView;
use App\Model\Scope;
use Doctrine\DBAL\Connection;

final readonly class DashboardCountsReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private Connection $db,
    ) {
    }

    public function read(Scope $scope): ?DashboardPanelView
    {
        $row = $this->db->fetchAssociative(
            'SELECT *, computed_at
               FROM agg_allocations_counts
              WHERE scope_type = :t AND scope_id = :i
                AND period_gran = :g AND period_key = :k',
            [
                't' => $scope->scopeType,
                'i' => $scope->scopeId,
                'g' => $scope->granularity,
                'k' => $scope->periodKey,
            ]
        );

        if (false === $row) {
            return null;
        }

        $total = (int) $row['total'];
        $pct = static fn (int $v): float => $total > 0 ? \round(100 * $v / $total, 1) : 0.0;

        return new DashboardPanelView(
            scope: $scope,
            total: $total,
            computedAt: new \DateTimeImmutable((string) $row['computed_at']),
            genderM: (int) $row['gender_m'],
            genderW: (int) $row['gender_w'],
            genderD: (int) $row['gender_d'],
            genderU: (int) $row['gender_u'],
            urg1: (int) $row['urg_1'],
            urg2: (int) $row['urg_2'],
            urg3: (int) $row['urg_3'],
            cathlabRequired: (int) $row['cathlab_required'],
            resusRequired: (int) $row['resus_required'],
            isCpr: (int) $row['is_cpr'],
            isVentilated: (int) $row['is_ventilated'],
            isShock: (int) $row['is_shock'],
            isPregnant: (int) $row['is_pregnant'],
            withPhysician: (int) $row['with_physician'],
            infectious: (int) $row['infectious'],
            pctMale: $pct((int) $row['gender_m']),
            pctFemale: $pct((int) $row['gender_w']),
            pctDiverse: $pct((int) $row['gender_d']),
            pctVentilated: $pct((int) $row['is_ventilated']),
            pctCpr: $pct((int) $row['is_cpr']),
            pctShock: $pct((int) $row['is_shock']),
            pctPregnant: $pct((int) $row['is_pregnant']),
            pctWithPhysician: $pct((int) $row['with_physician']),
            pctInfectious: $pct((int) $row['infectious']),
            pctUrg1: $pct((int) $row['urg_1']),
            pctUrg2: $pct((int) $row['urg_2']),
            pctUrg3: $pct((int) $row['urg_3']),
            pctCathlabRequired: $pct((int) $row['cathlab_required']),
            pctResusRequired: $pct((int) $row['resus_required']),
        );
    }
}
