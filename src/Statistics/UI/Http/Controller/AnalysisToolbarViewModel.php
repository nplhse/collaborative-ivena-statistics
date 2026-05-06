<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

final readonly class AnalysisToolbarViewModel
{
    public function __construct(
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
    ) {
    }
}
