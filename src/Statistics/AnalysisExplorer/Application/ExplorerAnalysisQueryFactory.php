<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\User\Domain\Entity\User;

final readonly class ExplorerAnalysisQueryFactory
{
    public function __construct(
        private StatisticsContextFactory $statisticsContextFactory,
        private StatisticsScopeResolver $statisticsScopeResolver,
    ) {
    }

    public function create(AnalysisViewConfig $config, ?User $user): AnalysisQuery
    {
        $context = $this->statisticsContextFactory->create($user, $config->statisticsFilter);

        return new AnalysisQuery(
            dataSourceKey: $config->dataSourceKey,
            metricKey: $config->metricKey,
            dimensionGrain: $config->dimensionGrain,
            scopeCriteria: $this->statisticsScopeResolver->resolveCriteria($context),
            periodBounds: StatisticsPeriodResolver::resolve($config->statisticsFilter),
        );
    }
}
