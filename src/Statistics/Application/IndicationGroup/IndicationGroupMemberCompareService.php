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
     *     shareWithinGroup: float
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
            ];
        }

        return $result;
    }

    /**
     * @param list<array{
     *     indicationId: int,
     *     label: string,
     *     total: int,
     *     withPhysicianShare: float,
     *     resusShare: float,
     *     urgencyEmergencyShare: float,
     *     shareWithinGroup: float
     * }> $memberRows
     *
     * @return list<array{indicationId: int, label: string, total: int}>
     */
    public function buildComparePickerRows(IndicationSubject $subject, array $memberRows): array
    {
        $totalsById = [];
        foreach ($memberRows as $row) {
            $totalsById[$row['indicationId']] = $row['total'];
        }

        $rows = [];
        foreach ($subject->indicationIds as $indicationId) {
            $rows[] = [
                'indicationId' => $indicationId,
                'label' => $this->indicationRepository->getDatalistLabelById($indicationId) ?? (string) $indicationId,
                'total' => $totalsById[$indicationId] ?? 0,
            ];
        }

        usort(
            $rows,
            static function (array $a, array $b): int {
                $byTotal = $b['total'] <=> $a['total'];
                if (0 !== $byTotal) {
                    return $byTotal;
                }

                return strcmp($a['label'], $b['label']);
            },
        );

        return $rows;
    }
}
