<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholds;
use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildTelemetry;
use PHPUnit\Framework\TestCase;

final class ClosureRebuildTelemetryThresholdsTest extends TestCase
{
    public function testAQuickSinglePassRunExceedsNothing(): void
    {
        self::assertSame([], ClosureRebuildTelemetry::exceededThresholds($this->thresholds(), 860.0, 107 * 1024 * 1024, 1));
    }

    public function testSlowLongMemoryAndPassesAreReported(): void
    {
        self::assertSame(['slow'], ClosureRebuildTelemetry::exceededThresholds($this->thresholds(), 1801.0, 0, 1));
        self::assertSame(['long_run', 'memory', 'passes'], ClosureRebuildTelemetry::exceededThresholds($this->thresholds(), 7201.0, 201 * 1024 * 1024, 2));
    }

    public function testASecondPassIsReportedWithoutConfiguredLimits(): void
    {
        self::assertSame(['passes'], ClosureRebuildTelemetry::exceededThresholds(new JobRunThresholds(), 99999.0, \PHP_INT_MAX, 2));
    }

    private function thresholds(): JobRunThresholds
    {
        return new JobRunThresholds(1800, 7200, 200 * 1024 * 1024);
    }
}
