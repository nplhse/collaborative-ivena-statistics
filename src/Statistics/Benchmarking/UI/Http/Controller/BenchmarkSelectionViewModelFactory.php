<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class BenchmarkSelectionViewModelFactory
{
    public function __construct(
        private StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private BenchmarkComparisonPageViewModelFactory $benchmarkComparisonPageViewModelFactory,
        private BenchmarkComparisonPeriodViewModelFactory $benchmarkComparisonPeriodViewModelFactory,
    ) {
    }

    public function create(
        Request $request,
        ?User $user,
        StatisticsFilter $primaryFilter,
        StatisticsFilter $comparisonFilter,
    ): BenchmarkSelectionViewModel {
        $routeName = 'app_stats_benchmarking';

        return new BenchmarkSelectionViewModel(
            $this->statisticsPageViewModelFactory->create($request, $routeName, $user, $primaryFilter),
            $this->overviewPeriodViewModelFactory->create($request, $routeName, $primaryFilter),
            $this->benchmarkComparisonPageViewModelFactory->create($request, $routeName, $user, $comparisonFilter),
            $this->benchmarkComparisonPeriodViewModelFactory->create($request, $routeName, $comparisonFilter),
        );
    }
}
