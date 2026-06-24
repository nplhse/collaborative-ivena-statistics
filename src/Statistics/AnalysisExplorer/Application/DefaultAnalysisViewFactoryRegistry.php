<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class DefaultAnalysisViewFactoryRegistry
{
    public function __construct(
        private DefaultAnalysisViewFactory $allocationsFactory,
        private DefaultHospitalsAnalysisViewFactory $hospitalsFactory,
    ) {
    }

    public function createDefault(AnalysisDataSourceKey $dataSourceKey, StatisticsFilter $statisticsFilter): AnalysisViewConfig
    {
        return match ($dataSourceKey) {
            AnalysisDataSourceKey::Allocations => $this->allocationsFactory->createDefault($statisticsFilter),
            AnalysisDataSourceKey::Hospitals => $this->hospitalsFactory->createDefault($statisticsFilter),
        };
    }
}
