<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Application\Dashboard;

use App\Content\Application\Dashboard\DTO\DashboardMetric;
use App\Content\Application\Dashboard\DTO\DashboardMetrics;
use PHPUnit\Framework\TestCase;

final class DashboardMetricsTest extends TestCase
{
    public function testValueReturnsMatchingMetric(): void
    {
        $metrics = new DashboardMetrics([
            new DashboardMetric('users', 12, 3, 'tabler:users', 'dashboard.metrics.users'),
            new DashboardMetric('imports', 4, 0, 'tabler:database-import', 'dashboard.metrics.imports'),
        ]);

        self::assertSame(12, $metrics->value('users'));
        self::assertSame(4, $metrics->value('imports'));
    }

    public function testValueThrowsForUnknownKey(): void
    {
        $metrics = new DashboardMetrics([
            new DashboardMetric('users', 1, 0, 'tabler:users', 'dashboard.metrics.users'),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown dashboard metric "allocations".');
        $metrics->value('allocations');
    }
}
