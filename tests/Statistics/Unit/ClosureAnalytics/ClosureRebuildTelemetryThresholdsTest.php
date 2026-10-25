<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Infrastructure\Monitoring\ClosureRebuildTelemetry;
use PHPUnit\Framework\TestCase;

final class ClosureRebuildTelemetryThresholdsTest extends TestCase
{
    public function testAQuickSinglePassRunExceedsNothing(): void
    {
        self::assertSame([], ClosureRebuildTelemetry::exceededThresholds(860.0, 107 * 1024 * 1024, 1));
    }

    public function testSlowLongMemoryAndPassesAreReported(): void
    {
        self::assertSame(['slow'], ClosureRebuildTelemetry::exceededThresholds(1801.0, 0, 1));
        self::assertSame(['long_run', 'memory', 'passes'], ClosureRebuildTelemetry::exceededThresholds(7201.0, 201 * 1024 * 1024, 2));
    }
}
