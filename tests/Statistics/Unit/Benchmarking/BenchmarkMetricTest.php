<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Benchmarking;

use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetric;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricFormat;
use App\Statistics\Benchmarking\Application\DTO\BenchmarkMetricKey;
use PHPUnit\Framework\TestCase;

final class BenchmarkMetricTest extends TestCase
{
    public function testRatioIsUndefinedWhenTheComparisonValueIsZero(): void
    {
        // 5 vs. 0 cases: 5 / 0 has no value, the builder stores null (#1252).
        $metric = new BenchmarkMetric(BenchmarkMetricKey::Total, 5.0, 0.0, 5.0, null, null, BenchmarkMetricFormat::Count);

        self::assertFalse($metric->hasRatio());
    }

    public function testRatioIsDefinedForAPositiveComparisonValue(): void
    {
        // 1,0 vs. 0,5 cases per day: ratio 2,0.
        $metric = new BenchmarkMetric(BenchmarkMetricKey::CasesPerDay, 1.0, 0.5, 0.5, 100.0, 2.0, BenchmarkMetricFormat::Decimal);

        self::assertTrue($metric->hasRatio());
    }
}
