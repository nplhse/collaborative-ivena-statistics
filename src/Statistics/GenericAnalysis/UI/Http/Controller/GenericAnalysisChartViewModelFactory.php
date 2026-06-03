<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartDataReducer;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartRecommendationService;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisChartSpecBuilder;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenericAnalysisChartViewModelFactory
{
    public function __construct(
        private GenericAnalysisChartRecommendationService $recommendationService,
        private GenericAnalysisChartSpecBuilder $specBuilder,
        private GenericAnalysisChartDataReducer $chartDataReducer,
        private TranslatorInterface $translator,
    ) {
    }

    public function create(AnalysisQuery $query, NormalizedAnalysisResult $result): GenericAnalysisChartViewModel
    {
        $recommendation = $this->recommendationService->recommend($query, $result);

        $allowedTypes = array_values(array_filter(
            $recommendation->allowedChartTypes,
            static fn (GenericAnalysisChartType $type): bool => $type->supportsApexChart(),
        ));

        $reducedChartData = $this->chartDataReducer->reduce($query, $result);
        $warnings = $recommendation->warnings;
        if ($reducedChartData->wasLimited()) {
            $warnings[] = $this->translator->trans('stats.generic_analysis.chart.warning.top_limited', [
                'limit' => GenericAnalysisChartDataReducer::TOP_LIMIT,
            ]);
        }

        $specsByChartType = $recommendation->hasChart
            ? $this->specBuilder->buildSpecsForTypes($allowedTypes, $query, $result)
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
        );
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
