<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\UI\Application\StatisticsFilterNavigationContext;
use App\Statistics\UI\Application\StatisticsScopeViewModelBuilder;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModel;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Request;

final readonly class BenchmarkComparisonPageViewModelFactory
{
    public function __construct(
        private StatisticsScopeViewModelBuilder $scopeViewModelBuilder,
    ) {
    }

    public function create(Request $request, string $routeName, ?User $user, StatisticsFilter $filter): StatisticsPageViewModel
    {
        $scope = $this->scopeViewModelBuilder->build(
            $request,
            $routeName,
            $user,
            $filter,
            StatisticsFilterNavigationContext::forBenchmarking(),
        );

        return new StatisticsPageViewModel(
            $filter,
            $scope->scopeUrls,
            $scope->hospitalUrls,
            $scope->cohortScopeChoices,
            $scope->cohortDropdownSelectedName,
            $scope->periodUrls,
            $scope->accessibleHospitals,
            $scope->hospitalDropdownSelectedName,
            $user instanceof User,
            $scope->headingScope,
            $scope->headingPeriod,
            $scope->showUnscopedHint,
            $scope->scopePrimaryMenu,
            $scope->scopeSecondaryMenu,
            $scope->showScopeSecondaryPicker,
            $scope->scopePrimaryDropdownLabel,
            $scope->scopeSecondaryDropdownLabel,
        );
    }
}
