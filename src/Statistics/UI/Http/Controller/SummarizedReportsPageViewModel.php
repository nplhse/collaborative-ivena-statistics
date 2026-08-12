<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\SummarizedReport\ReportBuildResult;

final readonly class SummarizedReportsPageViewModel
{
    public function __construct(
        public ReportBuildResult $buildResult,
        public string $currentTypeKey,
        public string $headerTitleKey,
        public string $headerSubtitleKey,
        public string $periodLabel,
        public string $indexUrl,
        public ?OverviewPeriodViewModel $periodNavigation = null,
    ) {
    }
}
