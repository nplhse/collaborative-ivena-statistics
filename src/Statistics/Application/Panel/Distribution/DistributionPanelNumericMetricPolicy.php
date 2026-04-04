<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Panel\PanelDefinition;

final readonly class DistributionPanelNumericMetricPolicy
{
    private function __construct(
        private PanelDefinition $panel,
    ) {
    }

    public static function for(PanelDefinition $panel): self
    {
        return new self($panel);
    }

    public function hasConfiguredMetric(): bool
    {
        $m = $this->panel->averageMetric;

        return \is_string($m) && '' !== trim($m);
    }

    public function metric(): ?DistributionNumericMetric
    {
        if (!$this->hasConfiguredMetric()) {
            return null;
        }

        return DistributionNumericMetric::tryFrom((string) $this->panel->averageMetric);
    }

    public function allowsAverageBars(): bool
    {
        return true === ($this->panel->controls['allow_bar_basis_average'] ?? false);
    }

    public function allowsBoxplotChart(): bool
    {
        return $this->hasConfiguredMetric()
            && true === ($this->panel->controls['allow_chart_type_boxplot'] ?? false);
    }

    /**
     * SQL fragment for WHERE / aggregates (validated column only).
     */
    public function projectionColumnSql(): string
    {
        return $this->metric()?->sqlColumn() ?? '';
    }

    public function needsNumericQuery(string $chartType): bool
    {
        if (!$this->hasConfiguredMetric() || null === $this->metric()) {
            return false;
        }

        return 'boxplot' === $chartType;
    }

    public function showChartTypeControl(): bool
    {
        return $this->allowsBoxplotChart();
    }

    public function showBarBasisControl(): bool
    {
        return $this->allowsAverageBars();
    }

    public function showMeanColumnInBarTable(string $chartType, string $barBasis): bool
    {
        return 'bar' === $chartType && 'average' === $barBasis;
    }

    public function isBoxplotTable(string $chartType): bool
    {
        return 'boxplot' === $chartType;
    }
}
