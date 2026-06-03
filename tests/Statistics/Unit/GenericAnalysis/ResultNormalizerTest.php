<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Cohort\HospitalCohortLabelResolver;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow;
use App\Statistics\GenericAnalysis\Application\MetricValueFormatter;
use App\Statistics\GenericAnalysis\Application\ResultNormalizer;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResultNormalizerTest extends TestCase
{
    private ResultNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = $this->createNormalizer();
    }

    public function testHospitalCohortDimensionUsesLabelResolver(): void
    {
        $result = new AnalysisResult(
            rows: [GenericAnalysisTestFixtures::resultRow('urban_basic', 4)],
            grandTotal: 4,
            primaryDimensionKey: 'hospital_cohort',
            metricKeys: ['count'],
        );

        $normalized = $this->normalizer->normalize(
            $result,
            'By cohort',
            $this->enrich($result),
            GenericAnalysisTestFixtures::defaultQuery('hospital_cohort'),
        );

        self::assertSame('Urban Location Basic Tier', $normalized->rows[0]->bucketLabel);
        self::assertStringNotContainsString('stats.filter.cohort.', $normalized->rows[0]->bucketLabel);
    }

    public function testHospitalDimensionUsesLookupNames(): void
    {
        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturnCallback(
            static fn (string $key): bool => 'hospital' === $key,
        );
        $entityLabelResolver->method('resolve')->willReturnCallback(
            static fn (string $key, array $ids): array => 'hospital' === $key ? [42 => 'Test Hospital'] : [],
        );

        $normalizer = $this->createNormalizer($entityLabelResolver);

        $result = new AnalysisResult(
            rows: [GenericAnalysisTestFixtures::resultRow(42, 3)],
            grandTotal: 3,
            primaryDimensionKey: 'hospital',
            metricKeys: ['count'],
        );

        $normalized = $normalizer->normalize($result, 'By hospital', $this->enrich($result), GenericAnalysisTestFixtures::defaultQuery('hospital'));

        self::assertSame('Test Hospital', $normalized->rows[0]->bucketLabel);
    }

    public function testDispatchAreaDimensionUsesEntityLabels(): void
    {
        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturnCallback(
            static fn (string $key): bool => 'dispatchArea' === $key,
        );
        $entityLabelResolver->method('resolve')->willReturnCallback(
            static fn (string $key, array $ids): array => 'dispatchArea' === $key ? [3 => 'North Region'] : [],
        );

        $normalizer = $this->createNormalizer($entityLabelResolver);

        $result = new AnalysisResult(
            rows: [GenericAnalysisTestFixtures::resultRow(3, 8)],
            grandTotal: 8,
            primaryDimensionKey: 'dispatchArea',
            metricKeys: ['count'],
        );

        $normalized = $normalizer->normalize($result, 'By dispatch area', $this->enrich($result), GenericAnalysisTestFixtures::defaultQuery('dispatchArea'));

        self::assertSame('North Region', $normalized->rows[0]->bucketLabel);
    }

    public function testFillsMissingMonthBucketsWithZero(): void
    {
        $result = new AnalysisResult(
            rows: [
                GenericAnalysisTestFixtures::resultRow(3, 5),
                GenericAnalysisTestFixtures::resultRow(6, 2),
            ],
            grandTotal: 7,
            primaryDimensionKey: 'month',
            metricKeys: ['count'],
        );

        $normalized = $this->normalizer->normalize(
            $result,
            'Test',
            $this->enrich($result, [
                ['percent_of_total' => 71.43, 'percent_of_bucket' => 100.0],
                ['percent_of_total' => 28.57, 'percent_of_bucket' => 100.0],
            ]),
            GenericAnalysisTestFixtures::defaultQuery('month'),
        );

        self::assertCount(12, $normalized->rows);
        self::assertSame(0, $normalized->rows[0]->countValue());
        self::assertSame(5, $normalized->rows[2]->countValue());
    }

    public function testNormalizesWeekdayWithBooleanSeriesBuckets(): void
    {
        $result = new AnalysisResult(
            rows: [
                GenericAnalysisTestFixtures::resultRow(1, 10, series: 1),
                GenericAnalysisTestFixtures::resultRow(1, 5, series: 0),
                GenericAnalysisTestFixtures::resultRow(2, 3, series: 1),
            ],
            grandTotal: 18,
            primaryDimensionKey: 'weekday',
            metricKeys: ['count'],
            seriesDimensionKey: 'resus',
        );

        $normalized = $this->normalizer->normalize(
            $result,
            'Test',
            $this->enrich($result, [
                ['percent_of_total' => 55.56, 'percent_of_bucket' => 66.67],
                ['percent_of_total' => 27.78, 'percent_of_bucket' => 33.33],
                ['percent_of_total' => 16.67, 'percent_of_bucket' => 100.0],
            ]),
            GenericAnalysisTestFixtures::defaultQuery('weekday', 'resus'),
        );

        self::assertGreaterThan(0, $normalized->grandTotal);
        self::assertContains('Yes', array_map(
            static fn (EnrichedAnalysisRow $row): ?string => $row->seriesLabel,
            array_filter($normalized->rows, static fn (EnrichedAnalysisRow $row): bool => $row->countValue() > 0),
        ));
    }

    public function testUrgencySeriesUsesProjectTranslationKeys(): void
    {
        $result = new AnalysisResult(
            rows: [
                GenericAnalysisTestFixtures::resultRow(1, 5, series: 1),
                GenericAnalysisTestFixtures::resultRow(1, 3, series: 2),
            ],
            grandTotal: 8,
            primaryDimensionKey: 'month',
            metricKeys: ['count'],
            seriesDimensionKey: 'urgency',
        );

        $normalized = $this->normalizer->normalize(
            $result,
            'Test',
            $this->enrich($result, [
                ['percent_of_total' => 62.5, 'percent_of_bucket' => 62.5],
                ['percent_of_total' => 37.5, 'percent_of_bucket' => 37.5],
            ]),
            GenericAnalysisTestFixtures::defaultQuery('month', 'urgency'),
        );

        $seriesLabels = array_values(array_unique(array_filter(array_map(
            static fn (EnrichedAnalysisRow $row): ?string => $row->seriesLabel,
            $normalized->rows,
        ))));

        self::assertSame(['U1', 'U2'], $seriesLabels);
    }

    public function testOmitsAgeGroupUnknownSeriesWhenNullBucketsDisabled(): void
    {
        $result = new AnalysisResult(
            rows: [
                GenericAnalysisTestFixtures::resultRow(1, 5, series: '0_18'),
            ],
            grandTotal: 5,
            primaryDimensionKey: 'month',
            metricKeys: ['count'],
            seriesDimensionKey: 'age_group',
            includeNullBuckets: false,
        );

        $normalized = $this->normalizer->normalize(
            $result,
            'Test',
            $this->enrich($result, [['percent_of_total' => 100.0, 'percent_of_bucket' => 100.0]]),
            GenericAnalysisTestFixtures::defaultQuery('month', 'age_group'),
        );

        $seriesLabels = array_values(array_unique(array_filter(array_map(
            static fn (EnrichedAnalysisRow $row): ?string => $row->seriesLabel,
            $normalized->rows,
        ))));

        self::assertNotContains('Unknown', $seriesLabels);
        self::assertNotContains('unknown', array_map(
            static fn (EnrichedAnalysisRow $row): ?string => $row->seriesKey,
            $normalized->rows,
        ));
    }

    private function createNormalizer(
        ?GenericAnalysisEntityLabelResolverInterface $entityLabelResolver = null,
    ): ResultNormalizer {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.overview.hospital_summary.urgency_u1' => 'U1',
                'stats.overview.hospital_summary.urgency_u2' => 'U2',
                'stats.overview.hospital_summary.urgency_u3' => 'U3',
                default => $id,
            },
        );

        if (!$entityLabelResolver instanceof GenericAnalysisEntityLabelResolverInterface) {
            /** @var MockObject&GenericAnalysisEntityLabelResolverInterface $mock */
            $mock = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
            $mock->method('supports')->willReturn(false);
            $entityLabelResolver = $mock;
        }

        return new ResultNormalizer(
            new DimensionRegistry(),
            new MetricRegistry(),
            new MetricValueFormatter(new MetricRegistry()),
            $translator,
            $entityLabelResolver,
            $this->createCohortLabelResolver(),
        );
    }

    private function createCohortLabelResolver(): HospitalCohortLabelResolver
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => match ($id) {
                'hospital.location.Urban' => 'Urban Location',
                'hospital.location.Mixed' => 'Mixed Location',
                'hospital.location.Rural' => 'Rural Location',
                'hospital.tier.Basic' => 'Basic Tier',
                'hospital.tier.Extended' => 'Extended Tier',
                'hospital.tier.Full' => 'Full Tier',
                'stats.filter.cohort.label' => ($params['location'] ?? '').' '.($params['tier'] ?? ''),
                default => $id,
            },
        );

        return new HospitalCohortLabelResolver($translator);
    }

    /**
     * @param list<array{percent_of_total: float, percent_of_bucket: float}>|null $derivedByRow
     *
     * @return list<array{row: \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow, derivedMetrics: array<string, float>}>
     */
    private function enrich(AnalysisResult $result, ?array $derivedByRow = null): array
    {
        $enriched = [];
        foreach ($result->rows as $index => $row) {
            $derived = $derivedByRow[$index] ?? ['percent_of_total' => 100.0, 'percent_of_bucket' => 100.0];
            $enriched[] = [
                'row' => $row,
                'derivedMetrics' => [
                    'percent_of_total' => $derived['percent_of_total'],
                    'percent_of_bucket' => $derived['percent_of_bucket'],
                ],
            ];
        }

        return $enriched;
    }
}
