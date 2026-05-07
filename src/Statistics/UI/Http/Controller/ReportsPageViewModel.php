<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\Report\ReportDefinitionInterface;

final readonly class ReportsPageViewModel
{
    /**
     * @param list<ReportDefinitionInterface> $reportDefinitions
     * @param array<string, string>           $reportSelectUrls
     */
    public function __construct(
        public StatisticWidget $reportWidget,
        public array $reportDefinitions,
        public string $currentReportKey,
        public array $reportSelectUrls,
        public int $currentLimit,
        public string $headerTitleKey,
        public string $headerSubtitleKey,
    ) {
    }
}
