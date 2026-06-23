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

    private function formData(
        string $rowDimension = 'time',
        ?string $rowGrain = 'month',
        ?string $columnDimension = null,
        ?string $columnGrain = null,
        string $chartType = 'grouped_bar',
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
