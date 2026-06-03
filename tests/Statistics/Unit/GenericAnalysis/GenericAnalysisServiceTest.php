<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisService;
use App\Statistics\GenericAnalysis\Application\RelativeDistributionCalculator;
use App\Statistics\GenericAnalysis\Application\ResultNormalizer;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisServiceTest extends TestCase
{
    public function testRunReturnsNormalizedResultFromQuery(): void
    {
        $connection = $this->createMock(Connection::class);
        $result = $this->createMock(\Doctrine\DBAL\Result::class);
        $result->method('fetchAllAssociative')->willReturn([
            ['bucket' => 6, 'value' => 4],
        ]);
        $connection->method('executeQuery')->willReturn($result);

        $service = new GenericAnalysisService(
            new GenericAllocationAnalysisQuery(
                $connection,
                new GenericAllocationAnalysisSqlBuilder(
                    new DimensionRegistry(),
                    new GenericAnalysisScopeSqlFilter(),
                ),
            ),
            new RelativeDistributionCalculator(),
            $this->createResultNormalizer(),
        );

        $normalized = $service->run('Allocations by month', new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame('Allocations by month', $normalized->title);
        self::assertSame(4, $normalized->grandTotal);
        self::assertNotEmpty($normalized->chartData['labels'] ?? []);
    }

    public function testRunPresetUsesPresetTitle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn(
            $this->createMock(\Doctrine\DBAL\Result::class),
        );

        $service = new GenericAnalysisService(
            new GenericAllocationAnalysisQuery(
                $connection,
                new GenericAllocationAnalysisSqlBuilder(
                    new DimensionRegistry(),
                    new GenericAnalysisScopeSqlFilter(),
                ),
            ),
            new RelativeDistributionCalculator(),
            $this->createResultNormalizer(),
        );

        $normalized = $service->runPreset(
            new AnalysisPreset(key: 'allocations_by_month', title: 'Allocations by month', primaryDimensionKey: 'month'),
            new AnalysisQuery(
                primaryDimensionKey: 'month',
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(null),
            ),
        );

        self::assertSame('Allocations by month', $normalized->title);
    }

    private function createResultNormalizer(): ResultNormalizer
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        return new ResultNormalizer(new DimensionRegistry(), $translator, $entityLabelResolver);
    }
}
