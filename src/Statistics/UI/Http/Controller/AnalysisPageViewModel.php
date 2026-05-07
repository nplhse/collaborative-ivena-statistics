<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticWidget;

final readonly class AnalysisPageViewModel
{
    /**
     * @param list<AnalysisDefinitionInterface>                              $analysisDefinitions
     * @param array<string, string>                                          $analysisSelectUrls
     * @param array<int, array{labelKey: string, url: string, active: bool}> $pivotRowChoices
     * @param array<int, array{labelKey: string, url: string, active: bool}> $pivotColChoices
     * @param array<int, array{labelKey: string, url: string, active: bool}> $pivotMeasureChoices
     */
    public function __construct(
        public StatisticWidget $analysisWidget,
        public array $analysisDefinitions,
        public string $currentAnalysisKey,
        public array $analysisSelectUrls,
        public string $headerTitleKey,
        public string $headerSubtitleKey,
        public bool $showViewTabs,
        public bool $showChartTypeControls,
        public AnalysisToolbarViewModel $toolbar,
        public AnalysisComparisonControlsViewModel $comparisonControls,
        public array $pivotRowChoices,
        public array $pivotColChoices,
        public array $pivotMeasureChoices,
    ) {
    }
}
