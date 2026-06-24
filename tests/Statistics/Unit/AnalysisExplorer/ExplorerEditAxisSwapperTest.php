<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerEditAxisSwapper;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\Statistics\UI\Form\Data\StatisticsScopePeriodFormData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerEditAxisSwapperTest extends KernelTestCase
{
    private ExplorerEditAxisSwapper $swapper;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->swapper = self::getContainer()->get(ExplorerEditAxisSwapper::class);
    }

    public function testCannotSwapWithoutColumns(): void
    {
        $formData = $this->formData(columnDimension: null);

        self::assertFalse($this->swapper->canSwap($formData));
        self::assertSame($formData, $this->swapper->swap($formData));
    }

    public function testCanSwapTimeByGenderMatrix(): void
    {
        $formData = $this->formData(
            rowDimension: 'time',
            rowGrain: 'month',
            columnDimension: 'gender',
            columnGrain: 'total',
        );

        self::assertTrue($this->swapper->canSwap($formData));
    }

    public function testSwapTransposesAxesAndNormalizesGrains(): void
    {
        $formData = $this->formData(
            rowDimension: 'time',
            rowGrain: 'year',
            columnDimension: 'urgency',
            columnGrain: 'total',
            chartType: 'stacked_bar',
        );

        $swapped = $this->swapper->swap($formData);

        self::assertSame('urgency', $swapped->rowDimension);
        self::assertSame('total', $swapped->rowGrain);
        self::assertSame('time', $swapped->columnDimension);
        self::assertSame('year', $swapped->columnGrain);
        self::assertSame('stacked_bar', $swapped->chartType);
    }

    public function testCanSwapHospitalTierByLocationMatrix(): void
    {
        $formData = $this->formData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            columnDimension: 'hospital_location',
            columnGrain: 'total',
            metric: 'hospital_count',
            chartType: 'grouped_bar',
        );

        self::assertTrue($this->swapper->canSwap($formData));
    }

    public function testSwapTransposesHospitalAxes(): void
    {
        $formData = $this->formData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            columnDimension: 'hospital_location',
            columnGrain: 'total',
            metric: 'beds_distribution',
            chartType: 'box_plot',
        );

        $swapped = $this->swapper->swap($formData);

        self::assertSame('hospital_location', $swapped->rowDimension);
        self::assertSame('hospital_tier', $swapped->columnDimension);
        self::assertSame('box_plot', $swapped->chartType);
    }

    public function testCanSwapHospitalCompareModeWithoutManualColumn(): void
    {
        $formData = $this->formData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            columnDimension: null,
            metric: 'hospital_count',
            chartType: 'grouped_bar',
            hospitalPopulation: 'compare',
        );

        self::assertTrue($this->swapper->canSwap($formData));
    }

    public function testSwapHospitalCompareModeTransposesPopulationOntoRows(): void
    {
        $formData = $this->formData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_tier',
            rowGrain: 'total',
            columnDimension: null,
            metric: 'hospital_count',
            chartType: 'grouped_bar',
            hospitalPopulation: 'compare',
        );

        $swapped = $this->swapper->swap($formData);

        self::assertSame('hospital_population_group', $swapped->rowDimension);
        self::assertSame('hospital_tier', $swapped->columnDimension);
        self::assertSame('compare', $swapped->hospitalPopulation);
    }

    public function testSwapHospitalCompareMatrixBackToTierRows(): void
    {
        $formData = $this->formData(
            dataSource: 'hospitals',
            rowDimension: 'hospital_population_group',
            rowGrain: 'total',
            columnDimension: 'hospital_tier',
            columnGrain: 'total',
            metric: 'hospital_count',
            chartType: 'grouped_bar',
            hospitalPopulation: 'compare',
        );

        $swapped = $this->swapper->swap($formData);

        self::assertSame('hospital_tier', $swapped->rowDimension);
        self::assertSame('hospital_population_group', $swapped->columnDimension);
    }

    private function formData(
        string $dataSource = 'allocations',
        string $rowDimension = 'time',
        ?string $rowGrain = 'month',
        ?string $columnDimension = null,
        ?string $columnGrain = null,
        string $metric = 'allocation_count',
        string $chartType = 'grouped_bar',
        string $hospitalPopulation = 'participating',
    ): ExplorerEditFormData {
        return new ExplorerEditFormData(
            scopePeriod: new StatisticsScopePeriodFormData('public', null, 'all'),
            dataSource: $dataSource,
            rowDimension: $rowDimension,
            rowGrain: $rowGrain,
            columnDimension: $columnDimension,
            columnGrain: $columnGrain,
            metric: $metric,
            chartType: $chartType,
            hospitalPopulation: $hospitalPopulation,
        );
    }
}
