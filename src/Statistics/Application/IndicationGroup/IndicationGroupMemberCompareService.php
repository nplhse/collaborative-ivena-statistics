<?php

declare(strict_types=1);

namespace App\Statistics\Application\IndicationGroup;

use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\IndicationDashboard\IndicationSubject;
use App\Statistics\Infrastructure\Query\IndicationGroup\IndicationGroupMemberMetricsQuery;

final readonly class IndicationGroupMemberCompareService
{
    public function __construct(
        private IndicationGroupMemberMetricsQuery $memberMetricsQuery,
        private IndicationNormalizedRepository $indicationRepository,
    ) {
    }

    /**
     * @return list<array{
     *     indicationId: int,
     *     label: string,
     *     total: int,
     *     withPhysicianShare: float,
     *     resusShare: float,
     *     urgencyEmergencyShare: float,
     *     shareWithinGroup: float,
     *     compareUrl: null
     * }>
     */
    public function buildMemberRows(
        IndicationSubject $subject,
        StatisticsScopeCriteria $scope,
        StatisticsPeriodBounds $period,
    ): array {
        $rows = $this->memberMetricsQuery->fetch(
            $subject->indicationIds,
            $period->from,
            $period->toExclusive,
            $scope,
        );

        $groupTotal = array_sum(array_column($rows, 'total'));
        $result = [];

        foreach ($rows as $row) {
            $label = $this->indicationRepository->getDatalistLabelById($row['indicationId']) ?? (string) $row['indicationId'];
            $total = $row['total'];
            $result[] = [
                'indicationId' => $row['indicationId'],
                'label' => $label,
                'total' => $total,
                'withPhysicianShare' => $total > 0 ? round(100 * $row['withPhysician'] / $total, 1) : 0.0,
                'resusShare' => $total > 0 ? round(100 * $row['resus'] / $total, 1) : 0.0,
                'urgencyEmergencyShare' => $total > 0 ? round(100 * $row['urgencyEmergency'] / $total, 1) : 0.0,
                'shareWithinGroup' => $groupTotal > 0 ? round(100 * $total / $groupTotal, 1) : 0.0,
                'compareUrl' => null,
            ];
        }

        return $result;
    }
}
