<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartRecommendationService;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartSpecBuilder;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDimensionType;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisTableRowLimit;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisChartViewModelFactory
{
    public function __construct(
        private GenericAnalysisChartRecommendationService $recommendationService,
        private GenericAnalysisChartSpecBuilder $specBuilder,
        private GenericAnalysisChartDataReducer $chartDataReducer,
        private DimensionRegistry $dimensionRegistry,
        private StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(
        Request $request,
        string $presetKey,
        AnalysisQuery $query,
        NormalizedAnalysisResult $result,
    ): GenericAnalysisChartViewModel {
        $recommendation = $this->recommendationService->recommend($query, $result);

        $allowedTypes = array_values(array_filter(
            $recommendation->allowedChartTypes,
            static fn (GenericAnalysisChartType $type): bool => $type->supportsApexChart(),
        ));

        [$primaryBucketCap, $rowLimit, $showRowLimitControl] = $this->resolveRowLimit($request, $query, $result->rows);

        $reducedChartData = $this->chartDataReducer->reduce($query, $result, $primaryBucketCap);
        $warnings = $recommendation->warnings;
        if ($reducedChartData->limitedPrimaryBuckets && null !== $primaryBucketCap) {
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.top_limited', [
                'limit' => $primaryBucketCap,
            ]);
        }

        $specsByChartType = $recommendation->hasChart
            ? $this->specBuilder->buildSpecsForTypes($allowedTypes, $query, $result, $primaryBucketCap)
            : [];

        $defaultType = $recommendation->defaultChartType->value;
        if ($recommendation->hasChart && !isset($specsByChartType[$defaultType]) && [] !== $specsByChartType) {
            $defaultType = array_key_first($specsByChartType);
        }

        $initialSpec = $specsByChartType[$defaultType] ?? null;

        $chartTypeOptions = [];
        foreach ($recommendation->allowedChartTypes as $type) {
            if (GenericAnalysisChartType::Table === $type || !$type->supportsApexChart()) {
                continue;
            }
            if (!isset($specsByChartType[$type->value])) {
                continue;
            }
            $chartTypeOptions[] = [
                'value' => $type->value,
                'label' => $this->chartTypeLabel($type),
            ];
        }

        $subtitle = null;
        if (null !== $result->seriesDimensionLabel) {
            $subtitle = $this->translator->trans('stats.generic_analysis.chart.subtitle_with_series', [
                'primary' => $result->primaryDimensionLabel,
                'series' => $result->seriesDimensionLabel,
            ]);
        }

        return new GenericAnalysisChartViewModel(
            title: $result->title,
            subtitle: $subtitle,
            hasChart: $recommendation->hasChart && null !== $initialSpec,
            defaultChartType: $defaultType,
            allowedChartTypes: array_values(array_map(
                static fn (GenericAnalysisChartType $type): string => $type->value,
                array_filter(
                    $recommendation->allowedChartTypes,
                    static fn (GenericAnalysisChartType $type): bool => $type->supportsApexChart(),
                ),
            )),
            initialSpec: $initialSpec,
            specsByChartType: $specsByChartType,
            xAxisLabel: $result->primaryDimensionLabel,
            yAxisLabel: $this->translator->trans('stats.generic_analysis.chart.y_axis_allocations'),
            isRelative: false,
            valueLabel: $this->translator->trans('stats.generic_analysis.chart.value_allocations'),
            warnings: $warnings,
            emptyStateMessage: $this->translator->trans('stats.generic_analysis.chart.empty'),
            chartTypeOptions: $chartTypeOptions,
            showChartTypeSelector: \count($chartTypeOptions) > 1,
            exportPlanned: true,
            showRowLimitControl: $showRowLimitControl,
            activeRowLimit: $rowLimit,
            rowLimitUrls: $this->rowLimitUrls($request, $presetKey),
        );
    }

    /**
     * @param list<EnrichedAnalysisRow> $rows
     *
     * @return array{0: ?int, 1: GenericAnalysisTableRowLimit, 2: bool}
     */
    private function resolveRowLimit(Request $request, AnalysisQuery $query, array $rows): array
    {
        $seen = [];
        foreach ($rows as $row) {
            $seen[$row->bucketKey] = true;
        }
        $distinctBucketCount = \count($seen);
        $primary = $this->dimensionRegistry->get($query->primaryDimensionKey);
        $primaryIsTemporal = AnalysisDimensionType::Temporal === $primary->type;
        $rowLimit = GenericAnalysisTableRowLimit::resolve($request, $distinctBucketCount, $primaryIsTemporal);

        return [
            $rowLimit->cap(),
            $rowLimit,
            !$primaryIsTemporal && $distinctBucketCount > 5,
        ];
    }

    /**
     * @return array<int|string, string>
     */
    private function rowLimitUrls(Request $request, string $presetKey): array
    {
        $urls = [];
        foreach (GenericAnalysisTableRowLimit::cases() as $limit) {
            $urls[$limit->value] = $this->navigationUrlBuilder->build(
                $request,
                'app_stats_generic_analysis',
                [
                    'presetKey' => $presetKey,
                    GenericAnalysisQueryKeys::TOP => $limit->value,
                ],
            );
        }

        return $urls;
    }

    private function chartTypeLabel(GenericAnalysisChartType $type): string
    {
        return $this->translator->trans(match ($type) {
            GenericAnalysisChartType::Bar => 'stats.generic_analysis.chart.type.bar',
            GenericAnalysisChartType::Line => 'stats.generic_analysis.chart.type.line',
            GenericAnalysisChartType::StackedBar => 'stats.generic_analysis.chart.type.stacked_bar',
            GenericAnalysisChartType::GroupedBar => 'stats.generic_analysis.chart.type.grouped_bar',
            GenericAnalysisChartType::HorizontalBar => 'stats.generic_analysis.chart.type.horizontal_bar',
            GenericAnalysisChartType::PercentStackedBar => 'stats.generic_analysis.chart.type.percent_stacked_bar',
            GenericAnalysisChartType::Pie => 'stats.generic_analysis.chart.type.pie',
            GenericAnalysisChartType::Heatmap => 'stats.generic_analysis.chart.type.heatmap',
            GenericAnalysisChartType::Table => 'stats.generic_analysis.chart.type.table',
        });
    }
}
