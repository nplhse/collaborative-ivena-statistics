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
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisPivotChoicesFactory
{
    /** @var list<string> */
    private const array PIVOT_STALE_QUERY_KEYS = ['dimension', 'chart_measure', 'chart'];

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
            $request->query->getString('rows'),
            $request->query->getString('cols'),
            $request->query->getString('measure'),
        );

        $rows = [];
        $cols = [];
        $measures = [];

        foreach (AllocationPivotDimension::cases() as $axis) {
            $rows[] = [
                'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', [
                    'analysis' => 'allocation_pivot',
                    'rows' => $axis->value,
                    'view' => 'table',
                ], self::PIVOT_STALE_QUERY_KEYS),
                'active' => $selection->rows === $axis,
            ];
            $cols[] = [
                'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', [
                    'analysis' => 'allocation_pivot',
                    'cols' => $axis->value,
                    'view' => 'table',
                ], self::PIVOT_STALE_QUERY_KEYS),
                'active' => $selection->cols === $axis,
            ];
        }

        foreach (AllocationPivotMeasure::cases() as $measure) {
            $measures[] = [
                'labelKey' => 'stats.analysis.allocation_pivot.measure.'.$measure->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', [
                    'analysis' => 'allocation_pivot',
                    'measure' => $measure->value,
                    'view' => 'table',
                ], self::PIVOT_STALE_QUERY_KEYS),
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
            $request->query->getString('rows'),
            $request->query->getString('cols'),
            $request->query->getString('measure'),
        );

        $rows = [];
        $cols = [];
        $measures = [];

        foreach (HospitalPivotDimension::cases() as $axis) {
            $rows[] = [
                'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', [
                    'analysis' => 'hospital_pivot',
                    'rows' => $axis->value,
                    'view' => 'table',
                ], self::PIVOT_STALE_QUERY_KEYS),
                'active' => $selection->rows === $axis,
            ];
            $cols[] = [
                'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', [
                    'analysis' => 'hospital_pivot',
                    'cols' => $axis->value,
                    'view' => 'table',
                ], self::PIVOT_STALE_QUERY_KEYS),
                'active' => $selection->cols === $axis,
            ];
        }

        foreach (HospitalPivotMeasure::cases() as $measure) {
            $measures[] = [
                'labelKey' => 'stats.analysis.hospital_pivot.measure.'.$measure->value,
                'url' => $this->statisticsNavigationUrlBuilder->build($request, 'app_stats_analysis', [
                    'analysis' => 'hospital_pivot',
                    'measure' => $measure->value,
                    'view' => 'table',
                ], self::PIVOT_STALE_QUERY_KEYS),
                'active' => $selection->measure === $measure,
            ];
        }

        return ['rows' => $rows, 'cols' => $cols, 'measures' => $measures];
    }
}
