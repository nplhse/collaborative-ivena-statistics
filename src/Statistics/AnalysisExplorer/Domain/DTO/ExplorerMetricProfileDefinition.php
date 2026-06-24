<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\DTO;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\BoxPlotTableColumn;
use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerDistributionValueSource;

final readonly class ExplorerMetricProfileDefinition
{
    /**
     * @param list<BoxPlotTableColumn> $tableColumns
     */
    public function __construct(
        public AnalysisMetricKey $storageKey,
        public string $labelTranslationKey,
        public string $groupTranslationKey,
        public ChartPresentationType $chartType,
        public ExplorerDistributionValueSource $valueSource,
        public array $tableColumns,
        public bool $allowsAdditionalTableMetrics = false,
        public ?string $formatRegistryKey = null,
    ) {
    }
}
