<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;

final readonly class SavedExplorerViewLoader
{
    private const string INVALID_CONFIG_WARNING = 'stats.analysis_explorer.saved_view.invalid_config';

    public function __construct(
        private SavedExplorerViewRepository $repository,
        private ExplorerConfigMapper $configMapper,
        private DefaultAnalysisViewFactory $defaultAnalysisViewFactory,
    ) {
    }

    public function load(string $viewKey, StatisticsFilter $filter, ?User $user): SavedExplorerViewLoadResult
    {
        $savedView = $this->resolveView($viewKey);
        if (!$savedView instanceof SavedExplorerView) {
            return new SavedExplorerViewLoadResult(
                state: $this->configMapper->toStateArray($this->defaultAnalysisViewFactory->createDefault($filter)),
                notFound: true,
            );
        }

        if (!$savedView->isAccessibleBy($user)) {
            return new SavedExplorerViewLoadResult(
                state: $this->configMapper->toStateArray($this->defaultAnalysisViewFactory->createDefault($filter)),
                notFound: true,
            );
        }

        $configJson = $savedView->getConfigJson();
        if (!$this->isStructurallyValid($configJson)) {
            return new SavedExplorerViewLoadResult(
                state: $this->configMapper->toStateArray($this->defaultAnalysisViewFactory->createDefault($filter)),
                warnings: [self::INVALID_CONFIG_WARNING],
                view: $savedView,
                usedFallback: true,
            );
        }

        try {
            $state = $configJson;
            $this->applyFilterOverlay($state, $filter);
            $config = $this->configMapper->viewConfigFromState($state, $user);

            return new SavedExplorerViewLoadResult(
                state: $this->configMapper->toStateArray($config),
                view: $savedView,
            );
        } catch (\Throwable) {
            return new SavedExplorerViewLoadResult(
                state: $this->configMapper->toStateArray($this->defaultAnalysisViewFactory->createDefault($filter)),
                warnings: [self::INVALID_CONFIG_WARNING],
                view: $savedView,
                usedFallback: true,
            );
        }
    }

    private function resolveView(string $viewKey): ?SavedExplorerView
    {
        if ('' === trim($viewKey)) {
            return null;
        }

        if (ctype_digit($viewKey)) {
            $byId = $this->repository->find((int) $viewKey);

            return $byId instanceof SavedExplorerView ? $byId : null;
        }

        return $this->repository->findBySlug($viewKey);
    }

    /**
     * @param array<string, mixed> $configJson
     */
    private function isStructurallyValid(array $configJson): bool
    {
        if (!isset($configJson['schemaVersion'], $configJson['dataSource'], $configJson['query'], $configJson['presentation'])) {
            return false;
        }

        if (!\is_array($configJson['query']) || !\is_array($configJson['presentation'])) {
            return false;
        }

        if (!isset($configJson['query']['rows']) && !isset($configJson['query']['dimension'])) {
            return false;
        }

        return isset($configJson['query']['metric'])
            || (isset($configJson['query']['metrics'], $configJson['query']['visualMetric']));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function applyFilterOverlay(array &$state, StatisticsFilter $filter): void
    {
        $filterState = $this->configMapper->filterToStateArray($filter);
        if (!isset($state['query']) || !\is_array($state['query'])) {
            $state['query'] = [];
        }

        $state['query']['scope'] = $filterState['scope'];
        $state['query']['period'] = $filterState['period'];
    }
}
