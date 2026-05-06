<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Pivot\AllocationPivotDimension;
use App\Statistics\Application\Pivot\AllocationPivotMeasure;
use App\Statistics\Application\Pivot\AllocationPivotSelection;
use App\Statistics\Application\Pivot\HospitalPivotDimension;
use App\Statistics\Application\Pivot\HospitalPivotMeasure;
use App\Statistics\Application\Pivot\HospitalPivotSelection;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class AnalysisPagePresenter
{
    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    /**
     * @param list<AnalysisDefinitionInterface> $allDefinitions
     */
    public function present(
        Request $request,
        AnalysisRequestModel $analysisRequest,
        string $analysisKey,
        StatisticWidget $analysisWidget,
        array $allDefinitions,
    ): AnalysisPageViewModel {
        $activeDefinition = $this->findDefinition($allDefinitions, $analysisKey);

        $analysisSelectUrls = [];
        $analysisDefinitions = [];
        foreach ($allDefinitions as $item) {
            if ('pivot' === $item->key()) {
                continue;
            }
            $analysisDefinitions[] = $item;
            $analysisSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                ['analysis' => $item->key()],
            );
        }

        $pivotChoices = $this->buildPivotChoices($request, $analysisKey);
        if (StatisticWidgetType::PivotTable === $analysisWidget->type) {
            $analysisWidget = new StatisticWidget(
                StatisticWidgetType::PivotTable,
                $analysisWidget->id,
                array_merge($analysisWidget->payload, [
                    'pivotRowChoices' => $pivotChoices['rows'],
                    'pivotColChoices' => $pivotChoices['cols'],
                    'pivotMeasureChoices' => $pivotChoices['measures'],
                ]),
                $analysisWidget->title,
                $analysisWidget->actions,
            );
        }

        return new AnalysisPageViewModel(
            $analysisWidget,
            $analysisDefinitions,
            $analysisKey,
            $analysisSelectUrls,
            $activeDefinition->isPivotLike(),
            $activeDefinition->supportsDimensionSelector(),
            $activeDefinition->supportsChartMeasureSelector(
                $analysisRequest->dimension,
                $analysisRequest->view,
                $analysisRequest->chartType,
            ),
            $analysisRequest->view,
            $analysisRequest->chartType,
            $analysisRequest->dimension->value,
            $analysisRequest->chartMeasure->value,
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'chart']),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'table']),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'chart', 'chart' => 'line']),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'chart', 'chart' => 'bar']),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Total->value]),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Gender->value]),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Urgency->value]),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Resources->value]),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['dimension' => StatisticsAnalysisDimension::Features->value]),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['chart_measure' => StatisticsChartMeasure::Absolute->value]),
            $this->statisticsPageUrl($request, 'app_stats_analysis', ['chart_measure' => StatisticsChartMeasure::Share->value]),
            $pivotChoices['rows'],
            $pivotChoices['cols'],
            $pivotChoices['measures'],
        );
    }

    /**
     * @param list<AnalysisDefinitionInterface> $definitions
     */
    private function findDefinition(array $definitions, string $analysisKey): AnalysisDefinitionInterface
    {
        foreach ($definitions as $definition) {
            if ($definition->key() === $analysisKey) {
                return $definition;
            }
        }

        return $definitions[0];
    }

    /**
     * @return array{
     *   rows: array<int, array{labelKey: string, url: string, active: bool}>,
     *   cols: array<int, array{labelKey: string, url: string, active: bool}>,
     *   measures: array<int, array{labelKey: string, url: string, active: bool}>
     * }
     */
    private function buildPivotChoices(Request $request, string $analysisKey): array
    {
        $pivotStaleQueryKeys = ['dimension', 'chart_measure', 'chart'];
        $pivotRowChoices = [];
        $pivotColChoices = [];
        $pivotMeasureChoices = [];

        if ('allocation_pivot' === $analysisKey) {
            $selection = AllocationPivotSelection::fromQuery(
                $request->query->getString('rows'),
                $request->query->getString('cols'),
                $request->query->getString('measure'),
            );
            foreach (AllocationPivotDimension::cases() as $axis) {
                $pivotRowChoices[] = [
                    'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'allocation_pivot',
                        'rows' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->rows === $axis,
                ];
                $pivotColChoices[] = [
                    'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'allocation_pivot',
                        'cols' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->cols === $axis,
                ];
            }
            foreach (AllocationPivotMeasure::cases() as $measure) {
                $pivotMeasureChoices[] = [
                    'labelKey' => 'stats.analysis.allocation_pivot.measure.'.$measure->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'allocation_pivot',
                        'measure' => $measure->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->measure === $measure,
                ];
            }
        } elseif ('hospital_pivot' === $analysisKey) {
            $selection = HospitalPivotSelection::fromQuery(
                $request->query->getString('rows'),
                $request->query->getString('cols'),
                $request->query->getString('measure'),
            );
            foreach (HospitalPivotDimension::cases() as $axis) {
                $pivotRowChoices[] = [
                    'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'hospital_pivot',
                        'rows' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->rows === $axis,
                ];
                $pivotColChoices[] = [
                    'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'hospital_pivot',
                        'cols' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->cols === $axis,
                ];
            }
            foreach (HospitalPivotMeasure::cases() as $measure) {
                $pivotMeasureChoices[] = [
                    'labelKey' => 'stats.analysis.hospital_pivot.measure.'.$measure->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'hospital_pivot',
                        'measure' => $measure->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->measure === $measure,
                ];
            }
        }

        return [
            'rows' => $pivotRowChoices,
            'cols' => $pivotColChoices,
            'measures' => $pivotMeasureChoices,
        ];
    }

    /**
     * @param array<string, scalar|null> $replace
     * @param list<string>               $removeKeys
     */
    private function statisticsPageUrl(
        Request $request,
        string $routeName,
        array $replace = [],
        array $removeKeys = [],
    ): string {
        return $this->statisticsNavigationUrlBuilder->build($request, $routeName, $replace, $removeKeys);
    }
}
