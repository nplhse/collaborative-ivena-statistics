<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Domain\Entity\AnalysisViewUsage;
use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\Statistics\GenericAnalysis\Domain\Enum\GenericAnalysisChartType;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\Statistics\Infrastructure\Repository\AnalysisViewUsageRepository;
use App\User\Domain\Entity\User;

final readonly class AnalysisViewRecentService
{
    public function __construct(
        private AnalysisViewUsageRepository $usageRepository,
        private AnalysisViewRegistry $viewRegistry,
    ) {
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function lastUsed(User $user, int $limit = 10): array
    {
        return $this->mapUsages($this->usageRepository->findLastUsedForUser($user, $limit));
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function mostFrequent(User $user, int $limit = 10): array
    {
        return $this->mapUsages($this->usageRepository->findMostFrequentForUser($user, $limit));
    }

    /**
     * @param list<AnalysisViewUsage> $usages
     *
     * @return list<AnalysisViewDefinition>
     */
    private function mapUsages(array $usages): array
    {
        $views = [];
        foreach ($usages as $usage) {
            if (AnalysisViewSource::System === $usage->getSource()) {
                $key = $usage->getSystemViewKey();
                if (null !== $key && $this->viewRegistry->has($key)) {
                    $views[] = $this->viewRegistry->get($key);
                }

                continue;
            }

            $saved = $usage->getSavedView();
            if ($saved instanceof SavedAnalysisView) {
                $views[] = $this->toSavedViewDefinition($saved);
            }
        }

        return $views;
    }

    private function toSavedViewDefinition(SavedAnalysisView $saved): AnalysisViewDefinition
    {
        $config = $saved->getConfig();
        $baseView = null;
        $sourceKey = $saved->getSourceSystemViewKey();
        if (null !== $sourceKey && $this->viewRegistry->has($sourceKey)) {
            $baseView = $this->viewRegistry->get($sourceKey);
        }

        $savedId = $saved->getId();
        if (null === $savedId) {
            throw new \LogicException('Saved analysis view must be persisted before use.');
        }

        return new AnalysisViewDefinition(
            key: 'saved_'.$savedId,
            title: $saved->getTitle(),
            description: $saved->getDescription() ?? '',
            category: $baseView instanceof AnalysisViewDefinition ? $baseView->category : AnalysisViewCategory::TimeAndTrends,
            tags: $baseView instanceof AnalysisViewDefinition ? $baseView->tags : ['saved'],
            primaryDimensionKey: $config->primaryDimensionKey,
            secondaryDimensionKey: $config->secondaryDimensionKey,
            metricKeys: $config->metricKeys,
            visualMetricKey: $config->visualMetricKey,
            chartType: null !== $config->chartType
                ? GenericAnalysisChartType::from($config->chartType)
                : ($baseView instanceof AnalysisViewDefinition ? $baseView->chartType : GenericAnalysisChartType::Bar),
            allowedChartTypes: $baseView instanceof AnalysisViewDefinition ? $baseView->allowedChartTypes : [],
            includeNullBuckets: $config->includeNullBuckets,
            legacyPresetKey: $sourceKey,
        );
    }
}
