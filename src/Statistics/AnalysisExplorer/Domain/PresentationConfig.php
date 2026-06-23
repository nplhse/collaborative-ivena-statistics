<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain;

use App\Statistics\AnalysisExplorer\Domain\Enum\ChartPresentationType;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\PresentationMode;
use App\Statistics\AnalysisExplorer\Domain\Enum\TableLayout;

final readonly class PresentationConfig
{
    public function __construct(
        public ChartPresentationType $chartType,
        public PresentationMode $mode = PresentationMode::Chart,
        public TableLayout $tableLayout = TableLayout::Flat,
        public ExplorerChartRowLimit $chartRowLimit = ExplorerChartRowLimit::All,
    ) {
    }

    public function withMode(PresentationMode $mode): self
    {
        return new self(
            chartType: $this->chartType,
            mode: $mode,
            tableLayout: $this->tableLayout,
            chartRowLimit: $this->chartRowLimit,
        );
    }

    public function withTableLayout(TableLayout $tableLayout): self
    {
        return new self(
            chartType: $this->chartType,
            mode: $this->mode,
            tableLayout: $tableLayout,
            chartRowLimit: $this->chartRowLimit,
        );
    }

    public function withChartRowLimit(ExplorerChartRowLimit $chartRowLimit): self
    {
        return new self(
            chartType: $this->chartType,
            mode: $this->mode,
            tableLayout: $this->tableLayout,
            chartRowLimit: $chartRowLimit,
        );
    }
}
