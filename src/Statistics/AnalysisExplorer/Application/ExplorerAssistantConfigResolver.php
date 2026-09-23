<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantReadiness;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\Application\DTO\StatisticsFilter;

final readonly class ExplorerAssistantConfigResolver
{
    public function __construct(
        private ExplorerAssistantConfigFactory $configFactory,
        private AnalysisViewConfigNormalizer $configNormalizer,
        private AnalysisViewConfigValidator $configValidator,
    ) {
    }

    public function resolve(ExplorerAssistantQuery $query, StatisticsFilter $filter): ?AnalysisViewConfig
    {
        if (ExplorerAssistantReadiness::Ready !== $this->configFactory->status($query)) {
            return null;
        }

        $draft = $this->configFactory->create($query, $filter);
        if (!$draft instanceof AnalysisViewConfig) {
            return null;
        }

        $normalized = $this->configNormalizer->normalize($draft);

        try {
            $this->configValidator->validate($normalized);
        } catch (InvalidExplorerConfigException) {
            return null;
        }

        if (!$this->matchesDraft($draft, $normalized)) {
            return null;
        }

        return $normalized;
    }

    private function matchesDraft(AnalysisViewConfig $draft, AnalysisViewConfig $normalized): bool
    {
        if ($draft->rowAxis->dimensionKey !== $normalized->rowAxis->dimensionKey) {
            return false;
        }

        if ($draft->columnAxis?->dimensionKey !== $normalized->columnAxis?->dimensionKey) {
            return false;
        }

        if ($draft->visualMetricKey !== $normalized->visualMetricKey) {
            return false;
        }

        if ($draft->presentation->chartType !== $normalized->presentation->chartType) {
            return false;
        }

        if ($draft->showsPercentOfTotal() !== $normalized->showsPercentOfTotal()) {
            return false;
        }

        return $draft->presentation->chartRowLimit === $normalized->presentation->chartRowLimit;
    }
}
