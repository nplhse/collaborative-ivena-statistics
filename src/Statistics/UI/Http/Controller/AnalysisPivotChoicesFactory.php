<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Pivot\AllocationPivotDimension;
use App\Statistics\Application\Pivot\AllocationPivotMeasure;
use App\Statistics\Application\Pivot\AllocationPivotSelection;
use App\Statistics\Application\Pivot\HospitalPivotDimension;
use App\Statistics\Application\Pivot\HospitalPivotMeasure;
use App\Statistics\Application\Pivot\HospitalPivotSelection;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisPivotChoicesFactory
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    /**
     * @return array{
     *   rows: array<int, array{labelKey: string, url: string, active: bool}>,
     *   cols: array<int, array{labelKey: string, url: string, active: bool}>,
     *   measures: array<int, array{labelKey: string, url: string, active: bool}>
     * }
     */
    public function build(Request $request, string $analysisKey): array
    {
        return match ($analysisKey) {
            'allocation_pivot' => $this->buildAllocationChoices($request),
            'hospital_pivot' => $this->buildHospitalChoices($request),
            default => ['rows' => [], 'cols' => [], 'measures' => []],
        };
    }

    /**
     * @return array{
     *   rows: array<int, array{labelKey: string, url: string, active: bool}>,
     *   cols: array<int, array{labelKey: string, url: string, active: bool}>,
     *   measures: array<int, array{labelKey: string, url: string, active: bool}>
     * }
     */
    private function buildAllocationChoices(Request $request): array
    {
        $selection = AllocationPivotSelection::fromQuery(
            $request->query->getString(StatisticsQueryKeys::ROWS),
            $request->query->getString(StatisticsQueryKeys::COLS),
            $request->query->getString(StatisticsQueryKeys::MEASURE),
        );

        $rows = [];
        $cols = [];
        $measures = [];

        foreach (AllocationPivotDimension::cases() as $axis) {
            $rows[] = [
                'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_pivot_tables', [
                    StatisticsQueryKeys::ANALYSIS => 'allocation_pivot',
                    StatisticsQueryKeys::ROWS => $axis->value,
                    StatisticsQueryKeys::VIEW => 'table',
                ], StatisticsQueryKeys::PIVOT_STALE),
                'active' => $selection->rows === $axis,
            ];
            $cols[] = [
                'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_pivot_tables', [
                    StatisticsQueryKeys::ANALYSIS => 'allocation_pivot',
                    StatisticsQueryKeys::COLS => $axis->value,
                    StatisticsQueryKeys::VIEW => 'table',
                ], StatisticsQueryKeys::PIVOT_STALE),
                'active' => $selection->cols === $axis,
            ];
        }

        foreach (AllocationPivotMeasure::cases() as $measure) {
            $measures[] = [
                'labelKey' => 'stats.analysis.allocation_pivot.measure.'.$measure->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_pivot_tables', [
                    StatisticsQueryKeys::ANALYSIS => 'allocation_pivot',
                    StatisticsQueryKeys::MEASURE => $measure->value,
                    StatisticsQueryKeys::VIEW => 'table',
                ], StatisticsQueryKeys::PIVOT_STALE),
                'active' => $selection->measure === $measure,
            ];
        }

        return ['rows' => $rows, 'cols' => $cols, 'measures' => $measures];
    }

    /**
     * @return array{
     *   rows: array<int, array{labelKey: string, url: string, active: bool}>,
     *   cols: array<int, array{labelKey: string, url: string, active: bool}>,
     *   measures: array<int, array{labelKey: string, url: string, active: bool}>
     * }
     */
    private function buildHospitalChoices(Request $request): array
    {
        $selection = HospitalPivotSelection::fromQuery(
            $request->query->getString(StatisticsQueryKeys::ROWS),
            $request->query->getString(StatisticsQueryKeys::COLS),
            $request->query->getString(StatisticsQueryKeys::MEASURE),
        );

        $rows = [];
        $cols = [];
        $measures = [];

        foreach (HospitalPivotDimension::cases() as $axis) {
            $rows[] = [
                'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_pivot_tables', [
                    StatisticsQueryKeys::ANALYSIS => 'hospital_pivot',
                    StatisticsQueryKeys::ROWS => $axis->value,
                    StatisticsQueryKeys::VIEW => 'table',
                ], StatisticsQueryKeys::PIVOT_STALE),
                'active' => $selection->rows === $axis,
            ];
            $cols[] = [
                'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_pivot_tables', [
                    StatisticsQueryKeys::ANALYSIS => 'hospital_pivot',
                    StatisticsQueryKeys::COLS => $axis->value,
                    StatisticsQueryKeys::VIEW => 'table',
                ], StatisticsQueryKeys::PIVOT_STALE),
                'active' => $selection->cols === $axis,
            ];
        }

        foreach (HospitalPivotMeasure::cases() as $measure) {
            $measures[] = [
                'labelKey' => 'stats.analysis.hospital_pivot.measure.'.$measure->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_pivot_tables', [
                    StatisticsQueryKeys::ANALYSIS => 'hospital_pivot',
                    StatisticsQueryKeys::MEASURE => $measure->value,
                    StatisticsQueryKeys::VIEW => 'table',
                ], StatisticsQueryKeys::PIVOT_STALE),
                'active' => $selection->measure === $measure,
            ];
        }

        return ['rows' => $rows, 'cols' => $cols, 'measures' => $measures];
    }
}
