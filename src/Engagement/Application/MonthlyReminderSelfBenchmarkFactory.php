<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;

final readonly class MonthlyReminderSelfBenchmarkFactory
{
    public function __construct(
        private MonthlyReminderComparisonFilterFactory $filterFactory,
        private BenchmarkCriteriaFactory $benchmarkCriteriaFactory,
        private BenchmarkReportService $benchmarkReportService,
    ) {
    }

    public function build(int $hospitalId, int $reportingYear, int $reportingMonth): BenchmarkReport
    {
        $monthFilter = $this->filterFactory->createPrimaryFilter(
            $hospitalId,
            StatisticsFilterPeriod::Month,
            $reportingYear,
            $reportingMonth,
        );
        $baselineFilter = $this->filterFactory->createPrimaryFilter(
            $hospitalId,
            StatisticsFilterPeriod::All,
            null,
            null,
        );

        $context = new StatisticsContext(null, $monthFilter);

        return $this->benchmarkReportService->build(
            $this->benchmarkCriteriaFactory->create($context, $baselineFilter),
        );
    }
}
