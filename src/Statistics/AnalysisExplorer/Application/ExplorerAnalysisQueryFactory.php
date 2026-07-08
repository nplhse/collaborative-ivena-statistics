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
        private ExplorerAnalysisFilterExpander $analysisFilterExpander,
        private ExplorerAnalysisFilterPolicy $analysisFilterPolicy,
    ) {
    }

    public function create(AnalysisViewConfig $config, ?User $user): AnalysisQuery
    {
        $context = $this->statisticsContextFactory->create($user, $config->statisticsFilter);
        $filters = $this->analysisFilterExpander->expand($config->filters);
        $filters = $this->analysisFilterPolicy->excludeAxisDimensions(
            $filters,
            $config->rowAxis,
            $config->columnAxis,
        );

        return new AnalysisQuery(
            dataSourceKey: $config->dataSourceKey,
            metricKeys: $config->metricKeys,
            visualMetricKey: $config->visualMetricKey,
            rowAxis: $config->rowAxis,
            columnAxis: $config->columnAxis,
            scopeCriteria: $this->statisticsScopeResolver->resolveCriteria($context),
            periodBounds: StatisticsPeriodResolver::resolve($config->statisticsFilter),
            hospitalPopulationMode: $config->hospitalPopulationMode,
            filters: $filters,
        );
    }
}
