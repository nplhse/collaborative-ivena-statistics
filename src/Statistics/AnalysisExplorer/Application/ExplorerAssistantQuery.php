<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerAssistantGoal;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerQueryKeys;

final readonly class ExplorerAssistantQuery
{
    public function __construct(
        public ?ExplorerAssistantGoal $goal,
        public ?AnalysisDimensionKey $row,
        public ?AnalysisDimensionKey $column,
        public ?AnalysisDimensionGrain $grain,
        public ?AnalysisDimensionGrain $columnGrain,
        public AnalysisMetricKey $metric,
        public AnalysisDataSourceKey $dataSource,
        public ExplorerAssistantFilters $filters,
        public bool $malformed,
    ) {
    }

    public function effectiveGrain(): AnalysisDimensionGrain
    {
        return $this->grain ?? AnalysisDimensionGrain::Month;
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        $params = [];
        if ($this->goal instanceof ExplorerAssistantGoal) {
            $params[ExplorerQueryKeys::GUIDE] = $this->goal->value;
            $params[ExplorerQueryKeys::METRIC] = $this->metric->value;
            $params[ExplorerQueryKeys::DATA_SOURCE] = $this->dataSource->value;
        }
        if ($this->row instanceof AnalysisDimensionKey) {
            $params[ExplorerQueryKeys::ROW] = $this->row->value;
        }
        if ($this->column instanceof AnalysisDimensionKey) {
            $params[ExplorerQueryKeys::COLUMN] = $this->column->value;
        }
        if ($this->grain instanceof AnalysisDimensionGrain) {
            $params[ExplorerQueryKeys::GRAIN] = $this->grain->value;
        }
        if ($this->columnGrain instanceof AnalysisDimensionGrain && $this->column?->isTemporalPrimary()) {
            $params[ExplorerQueryKeys::COLUMN_GRAIN] = $this->columnGrain->value;
        }

        return array_merge($params, $this->filters->parameters());
    }

    /**
     * @return array<string, string>
     */
    public function openParameters(): array
    {
        $params = $this->parameters();
        if (ExplorerAssistantGoal::TimeSeries === $this->goal || $this->row?->isTemporalPrimary()) {
            $params[ExplorerQueryKeys::GRAIN] = $this->effectiveGrain()->value;
        }

        return $params;
    }
}
