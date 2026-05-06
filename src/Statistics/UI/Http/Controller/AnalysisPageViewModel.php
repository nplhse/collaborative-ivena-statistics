<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticWidget;

final readonly class AnalysisPageViewModel
{
    /**
     * @param list<AnalysisDefinitionInterface> $analysisDefinitions
     * @param array<string, string>             $analysisSelectUrls
     * @param array<int, array{labelKey: string, url: string, active: bool}> $pivotRowChoices
     * @param array<int, array{labelKey: string, url: string, active: bool}> $pivotColChoices
     * @param array<int, array{labelKey: string, url: string, active: bool}> $pivotMeasureChoices
     */
    public function __construct(
        public StatisticWidget $analysisWidget,
        public array $analysisDefinitions,
        public string $currentAnalysisKey,
        public array $analysisSelectUrls,
        public bool $isPivotLike,
        public bool $showDimensionSelector,
        public bool $showChartMeasureSelector,
        public string $currentView,
        public string $currentChartType,
        public string $currentAnalysisDimension,
        public string $currentChartMeasure,
        public string $viewChartUrl,
        public string $viewTableUrl,
        public string $chartLineUrl,
        public string $chartBarUrl,
        public string $dimensionTotalUrl,
        public string $dimensionGenderUrl,
        public string $dimensionUrgencyUrl,
        public string $dimensionResourcesUrl,
        public string $dimensionFeaturesUrl,
        public string $chartMeasureAbsoluteUrl,
        public string $chartMeasureShareUrl,
        public array $pivotRowChoices,
        public array $pivotColChoices,
        public array $pivotMeasureChoices,
    ) {
    }
}
