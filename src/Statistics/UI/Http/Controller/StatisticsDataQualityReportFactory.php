<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\DataQuality\Application\DataQualityCriteria;
use App\Statistics\DataQuality\Application\DataQualityReportService;
use App\Statistics\DataQuality\Dto\DataQualityReport;
use App\User\Domain\Entity\User;

final readonly class StatisticsDataQualityReportFactory
{
    public function __construct(
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsScopeResolver $statisticsScopeResolver,
        private DataQualityReportService $dataQualityReportService,
    ) {
    }

    public function create(
        StatisticsFilter $filter,
        ?User $user,
        StatisticsPageViewModel $pageViewModel,
        OverviewPeriodViewModel $overviewPeriodViewModel,
        ?int $indicationId = null,
    ): DataQualityReport {
        $context = $this->statisticsContextFactory->create($user, $filter);
        $scope = $this->statisticsScopeResolver->resolveCriteria($context);
        $period = StatisticsPeriodResolver::resolve($filter);

        return $this->dataQualityReportService->build(new DataQualityCriteria(
            $indicationId,
            $filter,
            $scope,
            $period,
            $pageViewModel->headingScope,
            $overviewPeriodViewModel->headingLabel,
            $user,
        ));
    }
}
