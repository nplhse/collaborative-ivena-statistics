<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\ExplorerEditFormSummaryFactory;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ExplorerEditFormSummaryFactoryTest extends KernelTestCase
{
    private ExplorerEditFormSummaryFactory $factory;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->factory = self::getContainer()->get(ExplorerEditFormSummaryFactory::class);
    }

    public function testSummarizeTimeRowsWithGenderColumns(): void
    {
        $summary = $this->factory->summarize(new ExplorerEditFormData(
            rowDimension: 'time',
            rowGrain: 'month',
            columnDimension: 'gender',
            columnGrain: 'total',
            metric: 'allocation_count',
        ), null);

        self::assertStringContainsString('Month', $summary['row']);
        self::assertSame('Gender', $summary['column']);
        self::assertSame('Allocations', $summary['metric']);
    }

    public function testSummarizeWithoutColumns(): void
    {
        $summary = $this->factory->summarize(new ExplorerEditFormData(), null);

        self::assertStringContainsString('Month', $summary['row']);
        self::assertSame('None (single series)', $summary['column']);
        self::assertSame('Allocations', $summary['metric']);
    }
}
