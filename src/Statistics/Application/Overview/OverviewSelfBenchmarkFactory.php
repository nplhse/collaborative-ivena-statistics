<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;

final readonly class OverviewSelfBenchmarkFactory
{
    public function __construct(
        private BenchmarkCriteriaFactory $benchmarkCriteriaFactory,
        private BenchmarkReportService $benchmarkReportService,
    ) {
    }

    public function build(StatisticsContext $context): BenchmarkReport
    {
        $baselineFilter = $this->baselineFilterFor($context->filter);

        return $this->benchmarkReportService->build(
            $this->benchmarkCriteriaFactory->create($context, $baselineFilter),
        );
    }

    public function baselineFilterFor(StatisticsFilter $primary): StatisticsFilter
    {
        return new StatisticsFilter(
            $primary->scope,
            $primary->hospitalId,
            $primary->cohortType,
            self::baselinePeriodFor($primary->period),
            $primary->referenceYear,
            $primary->referenceMonth,
            $primary->referenceQuarter,
            null,
            false,
            $primary->stateId,
            $primary->dispatchAreaId,
        );
    }

    public static function baselinePeriodFor(StatisticsFilterPeriod $primaryPeriod): StatisticsFilterPeriod
    {
        return StatisticsFilterPeriod::All === $primaryPeriod
            ? StatisticsFilterPeriod::AllTime
            : StatisticsFilterPeriod::All;
    }
}
