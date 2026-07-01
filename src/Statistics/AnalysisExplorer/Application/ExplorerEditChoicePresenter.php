<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerEditChoicePresenter
{
    public function __construct(
        private ExplorerChoiceGrouper $choiceGrouper,
        private ExplorerDimensionCatalog $dimensionCatalog,
        private ExplorerMetricCatalog $metricCatalog,
        private ExplorerMetricProfileRegistry $profileRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<AnalysisDimensionKey> $dimensions
     *
     * @return array<string, array<string, string>>
     */
    public function groupedDimensionChoices(
        array $dimensions,
        AnalysisDataSourceKey $dataSourceKey,
        string $locale = 'en',
    ): array {
        return $this->choiceGrouper->groupChoices(
            $dimensions,
            $this->dimensionCatalog->categoryGroupOrderFor($dataSourceKey),
            fn (AnalysisDimensionKey $dimension): string => $dimension
                ->explorerCategory($dataSourceKey)
                ->labelTranslationKey(),
            fn (AnalysisDimensionKey $dimension): string => $this->translator->trans(
                'stats.analysis_explorer.dimension.'.$dimension->value,
                [],
                'statistics',
            ),
            static fn (AnalysisDimensionKey $dimension): string => $dimension->value,
            $locale,
        );
    }

    /**
     * @param list<AnalysisMetricKey> $metrics
     *
     * @return array<string, array<string, string>>
     */
    public function groupedMetricChoices(
        array $metrics,
        AnalysisDataSourceKey $dataSourceKey,
        string $locale = 'en',
    ): array {
        return $this->choiceGrouper->groupChoices(
            $metrics,
            $this->metricCatalog->metricGroupOrderFor($dataSourceKey),
            static fn (AnalysisMetricKey $metric): string => $metric->explorerGroupTranslationKey(),
            fn (AnalysisMetricKey $metric): string => $this->metricChoiceLabel($metric),
            static fn (AnalysisMetricKey $metric): string => $metric->value,
            $locale,
        );
    }

    private function metricChoiceLabel(AnalysisMetricKey $metric): string
    {
        $profile = $this->profileRegistry->profileFor($metric);
        if ($profile instanceof \App\Statistics\AnalysisExplorer\Domain\DTO\ExplorerMetricProfileDefinition) {
            return $this->translator->trans($profile->labelTranslationKey, [], 'statistics');
        }

        return $this->translator->trans('stats.analysis_explorer.metric.'.$metric->value, [], 'statistics');
    }
}
