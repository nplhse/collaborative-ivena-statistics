<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\RelativeDistributionCalculator;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResult;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisResultRow;
use PHPUnit\Framework\TestCase;

final class RelativeDistributionCalculatorTest extends TestCase
{
    private RelativeDistributionCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RelativeDistributionCalculator();
    }

    public function testPercentOfTotalAndPercentOfBucket(): void
    {
        $result = new AnalysisResult(
            rows: [
                new AnalysisResultRow(bucket: 1, value: 30, series: 'A'),
                new AnalysisResultRow(bucket: 1, value: 70, series: 'B'),
                new AnalysisResultRow(bucket: 2, value: 100, series: 'A'),
            ],
            grandTotal: 200,
            primaryDimensionKey: 'month',
            seriesDimensionKey: 'urgency',
        );

        $enriched = $this->calculator->enrich($result);

        self::assertSame(15.0, $enriched[0]['percent_of_total']);
        self::assertSame(30.0, $enriched[0]['percent_of_bucket']);
        self::assertSame(35.0, $enriched[1]['percent_of_total']);
        self::assertSame(70.0, $enriched[1]['percent_of_bucket']);
        self::assertSame(50.0, $enriched[2]['percent_of_total']);
        self::assertSame(100.0, $enriched[2]['percent_of_bucket']);
    }
}
