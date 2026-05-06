<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;

final readonly class AnalysisRequestModel
{
    public function __construct(
        public string $analysisKey,
        public string $view,
        public string $chartType,
        public StatisticsAnalysisDimension $dimension,
        public StatisticsChartMeasure $chartMeasure,
        public ?string $rows,
        public ?string $cols,
        public ?string $measure,
    ) {
    }
}
