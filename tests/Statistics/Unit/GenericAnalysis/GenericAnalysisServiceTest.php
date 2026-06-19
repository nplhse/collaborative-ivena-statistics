<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\AnalysisQueryExecutorRegistry;
use App\Statistics\GenericAnalysis\Application\AnalysisQueryModifierRegistry;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisService;
use App\Statistics\GenericAnalysis\Application\HospitalPopulationModifier;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Application\RelativeDistributionCalculator;
use App\Statistics\GenericAnalysis\Application\ResultNormalizer;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\AllocationAnalysisQueryExecutor;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisQuery;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAllocationAnalysisSqlBuilder;
use App\Statistics\GenericAnalysis\Infrastructure\Query\GenericAnalysisScopeSqlFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
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
            ['bucket' => 6, 'count' => 4],
        ]);
        $connection->method('executeQuery')->willReturn($result);

        $service = $this->createService($connection);

        $normalized = $service->run('Allocations by month', new AnalysisQuery(
            primaryDimensionKey: 'month',
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: new StatisticsPeriodBounds(null),
        ));

        self::assertSame('Allocations by month', $normalized->title);
        self::assertSame(4, $normalized->grandTotal);
        self::assertNotEmpty($normalized->chartData['labels'] ?? []);
        self::assertSame(['count'], $normalized->metricKeys);
    }

    public function testRunPresetUsesPresetTitle(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturn(
            $this->createMock(\Doctrine\DBAL\Result::class),
        );

        $service = $this->createService($connection);

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

    private function createService(Connection $connection): GenericAnalysisService
    {
        $dimensionRegistry = new DimensionRegistry();
        $metricRegistry = new MetricRegistry();

        return new GenericAnalysisService(
            new AnalysisQueryExecutorRegistry([
                new AllocationAnalysisQueryExecutor(
                    new GenericAllocationAnalysisQuery(
                        $connection,
                        new GenericAllocationAnalysisSqlBuilder(
                            $dimensionRegistry,
                            $metricRegistry,
                            new GenericAnalysisScopeSqlFilter(),
                        ),
                        $metricRegistry,
                    ),
                ),
            ]),
            new AnalysisQueryModifierRegistry([
                new HospitalPopulationModifier(),
            ]),
            new MetricCompatibilityChecker($metricRegistry, $dimensionRegistry),
            new RelativeDistributionCalculator(),
            $this->createResultNormalizer($dimensionRegistry, $metricRegistry),
        );
    }

    private function createResultNormalizer(
        DimensionRegistry $dimensionRegistry,
        MetricRegistry $metricRegistry,
    ): ResultNormalizer {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $cohortTranslator = $this->createMock(TranslatorInterface::class);
        $cohortTranslator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => match ($id) {
                'stats.filter.cohort.label' => ($params['location'] ?? '').' '.($params['tier'] ?? ''),
                default => $id,
            },
        );

        return new ResultNormalizer(
            $dimensionRegistry,
            $metricRegistry,
            new MetricValueFormatter($metricRegistry),
            $translator,
            $entityLabelResolver,
            new HospitalCohortLabelResolver($cohortTranslator),
        );
    }
}
