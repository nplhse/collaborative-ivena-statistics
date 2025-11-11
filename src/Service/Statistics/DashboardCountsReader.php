<?php

declare(strict_types=1);

namespace App\Service\Statistics;

use App\Model\DashboardPanelView;
use App\Model\Scope;
use App\Query\AggAllocationsCountsQuery;

/** @psalm-suppress ClassMustBeFinal */
readonly class DashboardCountsReader
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private AggAllocationsCountsQuery $query,
    ) {
    }

    public function read(Scope $scope): ?DashboardPanelView
    {
        $row = $this->query->fetchOne(
            $scope->scopeType,
            $scope->scopeId,
            $scope->granularity,
            $scope->periodKey
        );

        return $this->mapRow($scope, $row ?: null);
    }

    /**
     * @param string[] $periodKeys
     *
     * @return array<string, DashboardPanelView|null> map[periodKey] => view|null
     */
    public function readMany(Scope $scope, array $periodKeys): array
    {
        $rows = $this->query->fetchMany(
            $scope->scopeType,
            $scope->scopeId,
            $scope->granularity,
            $periodKeys
        );

        $out = [];
        foreach ($periodKeys as $pk) {
            $tmpScope = new Scope($scope->scopeType, $scope->scopeId, $scope->granularity, $pk);
            $out[$pk] = $this->mapRow($tmpScope, $rows[$pk] ?? null);
        }

        return $out;
    }

    private function mapRow(Scope $scope, ?array $row): ?DashboardPanelView
    {
        if (!$row) {
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
