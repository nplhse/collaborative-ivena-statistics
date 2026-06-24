<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application\Contract;

use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\User\Domain\Entity\User;

interface DataSourceCapabilitiesProviderInterface
{
    public function supports(AnalysisDataSourceKey $dataSourceKey): bool;

    public function capabilitiesFor(?User $user, StatisticsFilter $filter): DataSourceCapabilities;
}
