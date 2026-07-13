<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Application\DTO\AnalysisMatrix;
use App\Statistics\AnalysisExplorer\Application\DTO\ExplorerResultsTableViewModel;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisRunResult;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;

final readonly class ExplorerAnalysisPresentationState
{
    /**
     * @param array<string, array<string, mixed>> $chartSpecs
     */
    public function __construct(
        public ?AnalysisRunResult $result,
        public array $chartSpecs,
        public string $defaultChartType,
        public bool $hasChart,
        public bool $showChartRowLimitControl,
        public string $chartRowLimit,
        public ?ExplorerResultsTableViewModel $table,
        public ?string $emptyReason,
        public int $analysisRevisionDelta,
    ) {
    }

    public static function cleared(): self
    {
        return new self(
            result: null,
            chartSpecs: [],
            defaultChartType: 'bar',
            hasChart: false,
            showChartRowLimitControl: false,
            chartRowLimit: ExplorerChartRowLimit::All->value,
            table: null,
            emptyReason: null,
            analysisRevisionDelta: 0,
        );
    }
}

final readonly class ExplorerAnalysisPresentationCoordinator
{
    public function __construct(
        private ExplorerChartPresenter $chartPresenter,
        private ExplorerResultsTablePresenter $tablePresenter,
    ) {
    }

    public function present(
        ?AnalysisRunResult $result,
        ?AnalysisViewConfig $config,
        ?string $emptyReason,
    ): ExplorerAnalysisPresentationState {
        if (!$result instanceof AnalysisRunResult || !$config instanceof AnalysisViewConfig) {
            return ExplorerAnalysisPresentationState::cleared();
        }

        return new ExplorerAnalysisPresentationState(
            result: $result,
            chartSpecs: $this->chartPresenter->buildSpecs($result, $config->presentation),
            defaultChartType: $this->chartPresenter->defaultChartType($config->presentation),
            hasChart: $this->chartPresenter->hasChart($result),
            showChartRowLimitControl: $this->shouldShowChartRowLimitControl($config, $result),
            chartRowLimit: $config->presentation->chartRowLimit->value,
            table: $this->tablePresenter->create($config, $result),
            emptyReason: $emptyReason,
            analysisRevisionDelta: 1,
        );
    }

    public function rebuildCharts(
        AnalysisRunResult $result,
        AnalysisViewConfig $config,
    ): ExplorerAnalysisPresentationState {
        return new ExplorerAnalysisPresentationState(
            result: $result,
            chartSpecs: $this->chartPresenter->buildSpecs($result, $config->presentation),
            defaultChartType: $this->chartPresenter->defaultChartType($config->presentation),
            hasChart: $this->chartPresenter->hasChart($result),
            showChartRowLimitControl: $this->shouldShowChartRowLimitControl($config, $result),
            chartRowLimit: $config->presentation->chartRowLimit->value,
            table: null,
            emptyReason: null,
            analysisRevisionDelta: 1,
        );
    }

    public function shouldShowChartRowLimitControl(AnalysisViewConfig $config, ?AnalysisRunResult $result): bool
    {
        if ($config->rowAxis->dimensionKey->isTemporalPrimary()) {
            return false;
        }

        if (!$result instanceof AnalysisRunResult) {
            return false;
        }

        return $this->distinctRowBucketCount($result) > 5;
    }

    private function distinctRowBucketCount(AnalysisRunResult $result): int
    {
        if ($result->hasSeries()) {
            return \count(AnalysisMatrix::fromRunResult($result)->chartLabels());
        }

        $labels = [];
        foreach ($result->rows as $row) {
            $labels[$row->bucketLabel] = true;
        }

        return \count($labels);
    }
}
