<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\Statistics\GenericAnalysis\Application\ResultNormalizer;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ResultNormalizerTest extends TestCase
{
    private ResultNormalizer $normalizer;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id): string => match ($id) {
                'stats.overview.hospital_summary.urgency_u1' => 'U1',
                'stats.overview.hospital_summary.urgency_u2' => 'U2',
                'stats.overview.hospital_summary.urgency_u3' => 'U3',
                default => $id,
            },
        );

        $entityLabelResolver = $this->createMock(GenericAnalysisEntityLabelResolverInterface::class);
        $entityLabelResolver->method('supports')->willReturn(false);

        $this->normalizer = new ResultNormalizer(new DimensionRegistry(), $translator, $entityLabelResolver);
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

        $normalizer = new ResultNormalizer(
            new DimensionRegistry(),
            $this->createMock(TranslatorInterface::class),
            $entityLabelResolver,
        );

        $result = new AnalysisResult(
            rows: [new AnalysisResultRow(bucket: 42, value: 3)],
            grandTotal: 3,
            primaryDimensionKey: 'hospital',
        );

        $normalized = $normalizer->normalize($result, 'By hospital', [
            [
                'row' => $result->rows[0],
                'percent_of_total' => 100.0,
                'percent_of_bucket' => 100.0,
            ],
        ]);

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

        $normalizer = new ResultNormalizer(
            new DimensionRegistry(),
            $this->createMock(TranslatorInterface::class),
            $entityLabelResolver,
        );

        $result = new AnalysisResult(
            rows: [new AnalysisResultRow(bucket: 3, value: 8)],
            grandTotal: 8,
            primaryDimensionKey: 'dispatchArea',
        );

        $normalized = $normalizer->normalize($result, 'By dispatch area', [
            [
                'row' => $result->rows[0],
                'percent_of_total' => 100.0,
                'percent_of_bucket' => 100.0,
            ],
        ]);

        self::assertSame('North Region', $normalized->rows[0]->bucketLabel);
    }

    public function testFillsMissingMonthBucketsWithZero(): void
    {
        $result = new AnalysisResult(
            rows: [
                new AnalysisResultRow(bucket: 3, value: 5),
                new AnalysisResultRow(bucket: 6, value: 2),
            ],
            grandTotal: 7,
            primaryDimensionKey: 'month',
        );

        $enriched = [
            [
                'row' => $result->rows[0],
                'percent_of_total' => 71.43,
                'percent_of_bucket' => 100.0,
            ],
            [
                'row' => $result->rows[1],
                'percent_of_total' => 28.57,
                'percent_of_bucket' => 100.0,
            ],
        ];

        $normalized = $this->normalizer->normalize($result, 'Test', $enriched);

        self::assertCount(12, $normalized->rows);
        self::assertSame(0, $normalized->rows[0]->value);
        self::assertSame(5, $normalized->rows[2]->value);
    }

    public function testNormalizesWeekdayWithBooleanSeriesBuckets(): void
    {
        $result = new AnalysisResult(
            rows: [
                new AnalysisResultRow(bucket: 1, value: 10, series: 1),
                new AnalysisResultRow(bucket: 1, value: 5, series: 0),
                new AnalysisResultRow(bucket: 2, value: 3, series: 1),
            ],
            grandTotal: 18,
            primaryDimensionKey: 'weekday',
            seriesDimensionKey: 'resus',
        );

        $enriched = [
            [
                'row' => $result->rows[0],
                'percent_of_total' => 55.56,
                'percent_of_bucket' => 66.67,
            ],
            [
                'row' => $result->rows[1],
                'percent_of_total' => 27.78,
                'percent_of_bucket' => 33.33,
            ],
            [
                'row' => $result->rows[2],
                'percent_of_total' => 16.67,
                'percent_of_bucket' => 100.0,
            ],
        ];

        $normalized = $this->normalizer->normalize($result, 'Test', $enriched);

        self::assertGreaterThan(0, $normalized->grandTotal);
        self::assertContains('Yes', array_map(
            static fn (\App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow $row): ?string => $row->seriesLabel,
            array_filter($normalized->rows, static fn (\App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow $row): bool => $row->value > 0),
        ));
    }

    public function testUrgencySeriesUsesProjectTranslationKeys(): void
    {
        $result = new AnalysisResult(
            rows: [
                new AnalysisResultRow(bucket: 1, value: 5, series: 1),
                new AnalysisResultRow(bucket: 1, value: 3, series: 2),
            ],
            grandTotal: 8,
            primaryDimensionKey: 'month',
            seriesDimensionKey: 'urgency',
        );

        $enriched = [
            [
                'row' => $result->rows[0],
                'percent_of_total' => 62.5,
                'percent_of_bucket' => 62.5,
            ],
            [
                'row' => $result->rows[1],
                'percent_of_total' => 37.5,
                'percent_of_bucket' => 37.5,
            ],
        ];

        $normalized = $this->normalizer->normalize($result, 'Test', $enriched);

        $seriesLabels = array_values(array_unique(array_filter(array_map(
            static fn (\App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow $row): ?string => $row->seriesLabel,
            $normalized->rows,
        ))));

        self::assertSame(['U1', 'U2'], $seriesLabels);
    }

    public function testOmitsAgeGroupUnknownSeriesWhenNullBucketsDisabled(): void
    {
        $result = new AnalysisResult(
            rows: [
                new AnalysisResultRow(bucket: 1, value: 5, series: '0_18'),
            ],
            grandTotal: 5,
            primaryDimensionKey: 'month',
            seriesDimensionKey: 'age_group',
            includeNullBuckets: false,
        );

        $enriched = [
            [
                'row' => $result->rows[0],
                'percent_of_total' => 100.0,
                'percent_of_bucket' => 100.0,
            ],
        ];

        $normalized = $this->normalizer->normalize($result, 'Test', $enriched);

        $seriesLabels = array_values(array_unique(array_filter(array_map(
            static fn (\App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow $row): ?string => $row->seriesLabel,
            $normalized->rows,
        ))));

        self::assertNotContains('Unknown', $seriesLabels);
        self::assertNotContains('unknown', array_map(
            static fn (\App\Statistics\GenericAnalysis\Application\DTO\EnrichedAnalysisRow $row): ?string => $row->seriesKey,
            $normalized->rows,
        ));
    }
}
