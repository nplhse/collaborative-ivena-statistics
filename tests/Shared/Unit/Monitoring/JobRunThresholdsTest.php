<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Monitoring;

use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholdConfig;
use App\Shared\Infrastructure\Monitoring\JobRun\JobRunThresholds;
use PHPUnit\Framework\TestCase;

final class JobRunThresholdsTest extends TestCase
{
    public function testLongRunReplacesSlowAndMemoryIsCheckedOnItsOwn(): void
    {
        $thresholds = new JobRunThresholds(60, 600, 100);

        self::assertSame([], $thresholds->exceeded(60.0, 100));
        self::assertSame(['slow'], $thresholds->exceeded(61.0, 100));
        self::assertSame(['long_run', 'memory'], $thresholds->exceeded(601.0, 101));
    }

    public function testMissingLimitsAreNotChecked(): void
    {
        self::assertSame([], new JobRunThresholds()->exceeded(1.0e9, \PHP_INT_MAX));
        self::assertSame(['memory'], new JobRunThresholds(memoryBytes: 10)->exceeded(1.0e9, 11));
    }

    public function testTheConfigConvertsMegabytesAndKnowsUnconfiguredJobs(): void
    {
        $config = new JobRunThresholdConfig([
            'closure_rebuild' => ['slow_seconds' => 1800, 'long_seconds' => null, 'memory_mb' => 200],
        ]);

        self::assertEquals(new JobRunThresholds(1800, null, 200 * 1024 * 1024), $config->for('closure_rebuild'));
        self::assertEquals(new JobRunThresholds(), $config->for('allocation_import'));
    }
}
