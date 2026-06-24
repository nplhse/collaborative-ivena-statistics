<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\Contract\DataSourceCapabilitiesProviderInterface;
use App\Statistics\AnalysisExplorer\Domain\DataSourceCapabilities;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class DataSourceCapabilitiesRegistry
{
    /**
     * @param iterable<DataSourceCapabilitiesProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.analysis_explorer.capabilities_provider')]
        private iterable $providers,
    ) {
    }

    public function capabilitiesFor(
        AnalysisDataSourceKey $dataSourceKey,
        ?User $user,
        StatisticsFilter $filter,
    ): DataSourceCapabilities {
        foreach ($this->providers as $provider) {
            if ($provider->supports($dataSourceKey)) {
                return $provider->capabilitiesFor($user, $filter);
            }
        }

        throw new \InvalidArgumentException(sprintf('No capabilities provider for data source "%s".', $dataSourceKey->value));
    }
}
