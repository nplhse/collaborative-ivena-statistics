<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\DimensionKind;
use App\Statistics\Application\Panel\Distribution\DistributionNumericMetric;
use App\Statistics\Application\Panel\Distribution\DistributionPanelNumericMetricPolicy;
use App\Statistics\Application\Panel\PanelDefinition;
use PHPUnit\Framework\TestCase;

final class DistributionPanelNumericMetricPolicyTest extends TestCase
{
    public function testHasConfiguredMetricAndMetricWhenUnset(): void
    {
        $p = $this->panel(averageMetric: null);
        $policy = DistributionPanelNumericMetricPolicy::for($p);

        self::assertFalse($policy->hasConfiguredMetric());
        self::assertNull($policy->metric());
    }

    public function testMetricReturnsAgeEnum(): void
    {
        $p = $this->panel(averageMetric: 'age');
        $policy = DistributionPanelNumericMetricPolicy::for($p);

        self::assertTrue($policy->hasConfiguredMetric());
        self::assertSame(DistributionNumericMetric::Age, $policy->metric());
    }

    public function testAllowsAverageBarsDependsOnControl(): void
    {
        $off = $this->panel(averageMetric: 'age', controls: ['allow_bar_basis_average' => false]);
        $on = $this->panel(averageMetric: 'age', controls: ['allow_bar_basis_average' => true]);

        self::assertFalse(DistributionPanelNumericMetricPolicy::for($off)->allowsAverageBars());
        self::assertTrue(DistributionPanelNumericMetricPolicy::for($on)->allowsAverageBars());
    }

    public function testAllowsBoxplotRequiresMetricAndControl(): void
    {
        $noMetric = $this->panel(averageMetric: null, controls: ['allow_chart_type_boxplot' => true]);
        $metricNoControl = $this->panel(averageMetric: 'age', controls: ['allow_chart_type_boxplot' => false]);
        $full = $this->panel(averageMetric: 'age', controls: ['allow_chart_type_boxplot' => true]);

        self::assertFalse(DistributionPanelNumericMetricPolicy::for($noMetric)->allowsBoxplotChart());
        self::assertFalse(DistributionPanelNumericMetricPolicy::for($metricNoControl)->allowsBoxplotChart());
        self::assertTrue(DistributionPanelNumericMetricPolicy::for($full)->allowsBoxplotChart());
    }

    public function testNeedsNumericQueryOnlyForBoxplotWithMetric(): void
    {
        $with = $this->panel(averageMetric: 'age', controls: ['allow_chart_type_boxplot' => true]);
        $policy = DistributionPanelNumericMetricPolicy::for($with);

        self::assertFalse($policy->needsNumericQuery('bar'));
        self::assertTrue($policy->needsNumericQuery('boxplot'));
    }

    public function testNeedsNumericQueryFalseWithoutMetric(): void
    {
        $p = $this->panel(averageMetric: null);
        $policy = DistributionPanelNumericMetricPolicy::for($p);

        self::assertFalse($policy->needsNumericQuery('boxplot'));
    }

    public function testProjectionColumnSql(): void
    {
        self::assertSame('', DistributionPanelNumericMetricPolicy::for($this->panel(averageMetric: null))->projectionColumnSql());
        self::assertSame('age', DistributionPanelNumericMetricPolicy::for($this->panel(averageMetric: 'age'))->projectionColumnSql());
    }

    public function testShowChartTypeAndBarBasisControls(): void
    {
        $box = $this->panel(averageMetric: 'age', controls: ['allow_chart_type_boxplot' => true]);
        $avg = $this->panel(averageMetric: null, controls: ['allow_bar_basis_average' => true]);

        self::assertTrue(DistributionPanelNumericMetricPolicy::for($box)->showChartTypeControl());
        self::assertTrue(DistributionPanelNumericMetricPolicy::for($avg)->showBarBasisControl());
    }

    public function testShowMeanColumnInBarTable(): void
    {
        $p = $this->panel();
        $policy = DistributionPanelNumericMetricPolicy::for($p);

        self::assertTrue($policy->showMeanColumnInBarTable('bar', 'average'));
        self::assertFalse($policy->showMeanColumnInBarTable('bar', 'counts'));
        self::assertFalse($policy->showMeanColumnInBarTable('boxplot', 'average'));
    }

    public function testIsBoxplotTable(): void
    {
        $p = $this->panel();
        self::assertTrue(DistributionPanelNumericMetricPolicy::for($p)->isBoxplotTable('boxplot'));
        self::assertFalse(DistributionPanelNumericMetricPolicy::for($p)->isBoxplotTable('bar'));
    }

    /**
     * @param array<string, bool> $controls
     */
    private function panel(?string $averageMetric = 'age', array $controls = []): PanelDefinition
    {
        $base = [
            'allow_view_mode_toggle' => true,
            'allow_group_by' => true,
            'allow_bar_basis_average' => true,
            'allow_chart_type_boxplot' => true,
        ];

        return new PanelDefinition(
            key: 'urgency',
            type: 'distribution',
            dimensionKind: DimensionKind::Column,
            dimensionField: 'urgency_code',
            dimensionLabel: 'statistics.distribution.dim.urgency',
            groupByField: 'hospital_tier_code',
            groupByLabel: 'statistics.distribution.dim.hospital_tier',
            filters: ['date_range', 'hospital_tier', 'hospital_location'],
            options: ['default_view' => 'grouped', 'show_percent' => true],
            controls: array_replace($base, $controls),
            filterDefaults: [],
            averageMetric: $averageMetric,
        );
    }
}
