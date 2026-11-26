<?php

declare(strict_types=1);

namespace App\Statistics\Application\Overview;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkReport;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Benchmarks the scope against itself in the previous period of the same length (#1071).
 *
 * Without a comparison period (all time), both sides cover the same period; callers then show no comparison.
 */
final readonly class OverviewSelfBenchmarkFactory
{
    public function __construct(
        private BenchmarkCriteriaFactory $benchmarkCriteriaFactory,
        private BenchmarkReportService $benchmarkReportService,
        private TranslatorInterface $translator,
    ) {
    }

    public function build(StatisticsContext $context, ?OverviewComparisonPeriod $comparison): BenchmarkReport
    {
        if (!$comparison instanceof OverviewComparisonPeriod) {
            return $this->benchmarkReportService->buildForOverview(
                $this->benchmarkCriteriaFactory->create($context, $context->filter),
            );
        }

        return $this->benchmarkReportService->buildForOverview(
            $this->benchmarkCriteriaFactory->createForPeriods(
                $context,
                $comparison->primary,
                $comparison->comparison,
                $this->translator->trans($comparison->labelKey(), [], 'statistics'),
            ),
        );
    }
}
