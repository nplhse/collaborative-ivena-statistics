<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AllocationsCapabilitiesProvider;
use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormNormalizer;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use PHPUnit\Framework\TestCase;

final class ExplorerEditFormNormalizerTest extends TestCase
{
    private ExplorerEditFormNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new ExplorerEditFormNormalizer(new AllocationsCapabilitiesProvider());
    }

    public function testGenderWithEmptyGrainDefaultsToTotal(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            dimension: 'gender',
            timeGrain: null,
            chartType: 'bar',
        ));

        self::assertSame('gender', $normalized->dimension);
        self::assertSame('total', $normalized->timeGrain);
        self::assertSame('bar', $normalized->chartType);
    }

    public function testGenderWithMonthFallsBackFromBarToGroupedBar(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            dimension: 'gender',
            timeGrain: 'month',
            chartType: 'bar',
        ));

        self::assertSame('month', $normalized->timeGrain);
        self::assertSame('grouped_bar', $normalized->chartType);
    }

    public function testTimeDimensionRejectsTotalGrain(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            dimension: 'time',
            timeGrain: 'total',
            chartType: 'bar',
        ));

        self::assertSame('time', $normalized->dimension);
        self::assertSame('month', $normalized->timeGrain);
    }

    public function testUrgencyYearKeepsStackedBarChartType(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            dimension: 'urgency',
            timeGrain: 'year',
            chartType: 'stacked_bar',
        ));

        self::assertSame('urgency', $normalized->dimension);
        self::assertSame('year', $normalized->timeGrain);
        self::assertSame('stacked_bar', $normalized->chartType);
    }

    private function formData(string $dimension, ?string $timeGrain, string $chartType): ExplorerEditFormData
    {
        return new ExplorerEditFormData(
            scopePeriod: new StatisticsScopePeriodFormData('public', null, 'all'),
            dimension: $dimension,
            metric: 'allocation_count',
            timeGrain: $timeGrain,
            chartType: $chartType,
        );
    }
}
