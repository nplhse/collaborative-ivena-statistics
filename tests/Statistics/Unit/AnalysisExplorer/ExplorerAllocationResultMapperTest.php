<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisDimensionLabelResolver;
use App\Statistics\AnalysisExplorer\Application\ExplorerAllocationResultMapper;
use App\Statistics\AnalysisExplorer\Application\ExplorerMetricKeyMapper;
use App\Statistics\AnalysisExplorer\Domain\AnalysisQuery;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisResultRow;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow as GenericAnalysisResultRow;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ExplorerAllocationResultMapperTest extends TestCase
{
    public function testFillsMissingDaysInsideAClosedMonth(): void
    {
        $mapped = $this->mapper()->map(
            $this->analysisResult('day', [
                new GenericAnalysisResultRow('2024-04-01', ['count' => 12]),
                new GenericAnalysisResultRow('2024-04-03', ['count' => 17]),
            ]),
            $this->enriched([
                ['2024-04-01', 12],
                ['2024-04-03', 17],
            ]),
            $this->query(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Day),
                new StatisticsPeriodBounds(
                    new \DateTimeImmutable('2024-04-01 00:00:00'),
                    new \DateTimeImmutable('2024-05-01 00:00:00'),
                ),
            ),
        );

        self::assertCount(30, $mapped);
        self::assertSame('2024-04-01', $mapped[0]->bucket);
        self::assertSame(12, $mapped[0]->valueFor(AnalysisMetricKey::AllocationCount));
        self::assertSame('2024-04-02', $mapped[1]->bucket);
        self::assertSame(0, $mapped[1]->valueFor(AnalysisMetricKey::AllocationCount));
        self::assertSame('2024-04-03', $mapped[2]->bucket);
        self::assertSame(17, $mapped[2]->valueFor(AnalysisMetricKey::AllocationCount));
        self::assertSame('2024-04-30', $mapped[29]->bucket);
        self::assertNull($mapped[1]->seriesKey);
    }

    public function testFillsMissingMonthsAndSeriesInsideAClosedQuarter(): void
    {
        $mapped = $this->mapper()->map(
            $this->analysisResult('month', [
                new GenericAnalysisResultRow(4, ['count' => 10], '1'),
                new GenericAnalysisResultRow(6, ['count' => 8], '2'),
            ], 'gender'),
            $this->enriched([
                [4, 10, '1'],
                [6, 8, '2'],
            ]),
            $this->query(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Month),
                new StatisticsPeriodBounds(
                    new \DateTimeImmutable('2024-04-01 00:00:00'),
                    new \DateTimeImmutable('2024-07-01 00:00:00'),
                ),
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
            ),
        );

        $buckets = array_map(static fn (AnalysisResultRow $row): string => $row->bucket, $mapped);
        self::assertSame(['4', '4', '5', '5', '6', '6'], $buckets);
        self::assertSame(10, $this->metricAt($mapped, '4', '1'));
        self::assertSame(0, $this->metricAt($mapped, '4', '2'));
        self::assertSame(0, $this->metricAt($mapped, '5', '1'));
        self::assertSame(0, $this->metricAt($mapped, '5', '2'));
        self::assertSame(0, $this->metricAt($mapped, '6', '1'));
        self::assertSame(8, $this->metricAt($mapped, '6', '2'));
    }

    public function testLeavesYearGrainRowsUnfilled(): void
    {
        $mapped = $this->mapper()->map(
            $this->analysisResult('year', [
                new GenericAnalysisResultRow('2024', ['count' => 5]),
            ]),
            $this->enriched([['2024', 5]]),
            $this->query(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Year),
                new StatisticsPeriodBounds(
                    new \DateTimeImmutable('2024-01-01 00:00:00'),
                    new \DateTimeImmutable('2025-01-01 00:00:00'),
                ),
            ),
        );

        self::assertCount(1, $mapped);
        self::assertSame('2024', $mapped[0]->bucket);
        self::assertSame(5, $mapped[0]->valueFor(AnalysisMetricKey::AllocationCount));
    }

    public function testSkipsZeroFillForDistributionProfiles(): void
    {
        $mapped = $this->mapper()->map(
            $this->analysisResult('day', [
                new GenericAnalysisResultRow('2024-04-01', ['count' => 12]),
            ]),
            $this->enriched([['2024-04-01', 12]]),
            new AnalysisQuery(
                dataSourceKey: AnalysisDataSourceKey::Allocations,
                metricKeys: [AnalysisMetricKey::TransportTimeDistribution],
                visualMetricKey: AnalysisMetricKey::TransportTimeDistribution,
                rowAxis: AnalysisAxisRef::time(AnalysisDimensionGrain::Day),
                columnAxis: null,
                scopeCriteria: StatisticsScopeCriteria::public(),
                periodBounds: new StatisticsPeriodBounds(
                    new \DateTimeImmutable('2024-04-01 00:00:00'),
                    new \DateTimeImmutable('2024-05-01 00:00:00'),
                ),
            ),
        );

        self::assertCount(1, $mapped);
        self::assertSame('2024-04-01', $mapped[0]->bucket);
    }

    public function testLeavesNonTemporalRowsUnchanged(): void
    {
        $mapped = $this->mapper()->map(
            $this->analysisResult('gender', [
                new GenericAnalysisResultRow(1, ['count' => 3]),
            ]),
            $this->enriched([[1, 3]]),
            $this->query(
                AnalysisAxisRef::breakdown(AnalysisDimensionKey::Gender),
                new StatisticsPeriodBounds(
                    new \DateTimeImmutable('2024-04-01 00:00:00'),
                    new \DateTimeImmutable('2024-05-01 00:00:00'),
                ),
            ),
        );

        self::assertCount(1, $mapped);
        self::assertSame('1', $mapped[0]->bucket);
        self::assertSame(3, $mapped[0]->valueFor(AnalysisMetricKey::AllocationCount));
    }

    public function testEmptyEnrichedRowsStayEmpty(): void
    {
        $mapped = $this->mapper()->map(
            new AnalysisResult(
                rows: [],
                grandTotal: 0,
                primaryDimensionKey: 'day',
                metricKeys: ['count'],
            ),
            [],
            $this->query(
                AnalysisAxisRef::time(AnalysisDimensionGrain::Day),
                new StatisticsPeriodBounds(
                    new \DateTimeImmutable('2024-04-01 00:00:00'),
                    new \DateTimeImmutable('2024-05-01 00:00:00'),
                ),
            ),
        );

        self::assertSame([], $mapped);
    }

    private function mapper(): ExplorerAllocationResultMapper
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn('en');
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => $id,
        );

        $entityLabelResolver = $this->createStub(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        return new ExplorerAllocationResultMapper(
            new DimensionRegistry(),
            new AnalysisDimensionLabelResolver(
                $translator,
                $entityLabelResolver,
                new HospitalCohortLabelResolver($translator),
            ),
            new ExplorerMetricKeyMapper(),
        );
    }

    /**
     * @param list<GenericAnalysisResultRow> $rows
     */
    private function analysisResult(string $primaryDimensionKey, array $rows, ?string $seriesDimensionKey = null): AnalysisResult
    {
        return new AnalysisResult(
            rows: $rows,
            grandTotal: 0,
            primaryDimensionKey: $primaryDimensionKey,
            metricKeys: ['count'],
            seriesDimensionKey: $seriesDimensionKey,
        );
    }

    /**
     * @param list<array{0: int|string, 1: int, 2?: int|string}> $rows
     *
     * @return list<array{row: GenericAnalysisResultRow, derivedMetrics: array<string, float>}>
     */
    private function enriched(array $rows): array
    {
        $enriched = [];
        foreach ($rows as $row) {
            $enriched[] = [
                'row' => new GenericAnalysisResultRow($row[0], ['count' => $row[1]], $row[2] ?? null),
                'derivedMetrics' => [],
            ];
        }

        return $enriched;
    }

    private function query(
        AnalysisAxisRef $rowAxis,
        StatisticsPeriodBounds $bounds,
        ?AnalysisAxisRef $columnAxis = null,
    ): AnalysisQuery {
        return new AnalysisQuery(
            dataSourceKey: AnalysisDataSourceKey::Allocations,
            metricKeys: [AnalysisMetricKey::AllocationCount],
            visualMetricKey: AnalysisMetricKey::AllocationCount,
            rowAxis: $rowAxis,
            columnAxis: $columnAxis,
            scopeCriteria: StatisticsScopeCriteria::public(),
            periodBounds: $bounds,
        );
    }

    /**
     * @param list<AnalysisResultRow> $rows
     */
    private function metricAt(array $rows, string $bucket, string $seriesKey): int|float|null
    {
        foreach ($rows as $row) {
            if ($row->bucket === $bucket && $row->seriesKey === $seriesKey) {
                return $row->valueFor(AnalysisMetricKey::AllocationCount);
            }
        }

        self::fail(sprintf('Missing filled row for bucket %s and series %s.', $bucket, $seriesKey));
    }
}
