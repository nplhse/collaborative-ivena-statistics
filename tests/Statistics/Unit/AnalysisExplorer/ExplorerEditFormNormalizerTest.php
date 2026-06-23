<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormNormalizer;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerEditFormNormalizerTest extends KernelTestCase
{
    private ExplorerEditFormNormalizer $normalizer;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->normalizer = self::getContainer()->get(ExplorerEditFormNormalizer::class);
    }

    public function testGenderRowsWithEmptyGrainDefaultsToTotal(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'gender',
            rowGrain: null,
            chartType: 'bar',
        ));

        self::assertSame('gender', $normalized->rowDimension);
        self::assertSame('total', $normalized->rowGrain);
        self::assertNull($normalized->columnDimension);
    }

    public function testTimeRowsWithGenderColumnsFallsBackFromBarToGroupedBar(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'time',
            rowGrain: 'month',
            chartType: 'bar',
            columnDimension: 'gender',
            columnGrain: 'total',
        ));

        self::assertSame('time', $normalized->rowDimension);
        self::assertSame('gender', $normalized->columnDimension);
        self::assertSame('grouped_bar', $normalized->chartType);
    }

    public function testTimeRowsRejectsTotalGrain(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'time',
            rowGrain: 'total',
            chartType: 'bar',
        ));

        self::assertSame('time', $normalized->rowDimension);
        self::assertSame('month', $normalized->rowGrain);
    }

    public function testHospitalRowOnPublicScopeDowngradesToTime(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'hospital',
            rowGrain: 'total',
            chartType: 'bar',
        ));

        self::assertSame('time', $normalized->rowDimension);
        self::assertSame('month', $normalized->rowGrain);
    }

    public function testTimeRowsWithUrgencyColumnsKeepsStackedBarChartType(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'time',
            rowGrain: 'year',
            chartType: 'stacked_bar',
            columnDimension: 'urgency',
            columnGrain: 'total',
        ));

        self::assertSame('time', $normalized->rowDimension);
        self::assertSame('year', $normalized->rowGrain);
        self::assertSame('urgency', $normalized->columnDimension);
        self::assertSame('stacked_bar', $normalized->chartType);
    }

    public function testTimeRowsWithGenderColumnsForceColumnGrainTotal(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'time',
            rowGrain: 'month',
            chartType: 'grouped_bar',
            columnDimension: 'gender',
            columnGrain: 'month',
        ));

        self::assertSame('gender', $normalized->columnDimension);
        self::assertSame('total', $normalized->columnGrain);
    }

    public function testDepartmentRowsWithTimeColumnsDefaultColumnGrainToMonth(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'department',
            rowGrain: 'total',
            chartType: 'grouped_bar',
            columnDimension: 'time',
            columnGrain: null,
        ));

        self::assertSame('time', $normalized->columnDimension);
        self::assertSame('month', $normalized->columnGrain);
    }

    public function testDepartmentRowsWithTimeColumnsKeepValidSubmittedYearGrain(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'department',
            rowGrain: 'total',
            chartType: 'grouped_bar',
            columnDimension: 'time',
            columnGrain: 'year',
        ));

        self::assertSame('year', $normalized->columnGrain);
    }

    public function testChangingRowGrainDoesNotAffectBreakdownColumnGrain(): void
    {
        $normalized = $this->normalizer->normalize($this->formData(
            rowDimension: 'time',
            rowGrain: 'year',
            chartType: 'grouped_bar',
            columnDimension: 'urgency',
            columnGrain: 'year',
        ));

        self::assertSame('total', $normalized->columnGrain);
    }

    private function formData(
        string $rowDimension,
        ?string $rowGrain,
        string $chartType,
        ?string $columnDimension = null,
        ?string $columnGrain = null,
    ): ExplorerEditFormData {
        return new ExplorerEditFormData(
            scopePeriod: new StatisticsScopePeriodFormData('public', null, 'all'),
            rowDimension: $rowDimension,
            rowGrain: $rowGrain,
            columnDimension: $columnDimension,
            columnGrain: $columnGrain,
            metric: 'allocation_count',
            chartType: $chartType,
        );
    }
}
