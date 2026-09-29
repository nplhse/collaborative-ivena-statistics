<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\ClosureAnalytics\Infrastructure\Query\ClosureIntervalSqlFilter;
use PHPUnit\Framework\TestCase;

final class ClosureIntervalSqlFilterTest extends TestCase
{
    public function testBuildsHalfOpenOverlapAndHospitalScope(): void
    {
        [$where, $params] = ClosureIntervalSqlFilter::build(
            new StatisticsPeriodBounds(
                new \DateTimeImmutable('2026-01-01'),
                new \DateTimeImmutable('2026-02-01'),
            ),
            new StatisticsScopeCriteria([12, 13]),
        );

        self::assertStringContainsString('ci.hospital_id IN (:hospital_ids)', $where);
        self::assertStringContainsString('ci.ends_at > :period_from', $where);
        self::assertStringContainsString('ci.starts_at < :period_to', $where);
        self::assertSame([12, 13], $params['hospital_ids']);
    }

    public function testEmptyAuthorisedHospitalScopeNeverReturnsRows(): void
    {
        [$where] = ClosureIntervalSqlFilter::build(
            new StatisticsPeriodBounds(null),
            new StatisticsScopeCriteria([]),
        );

        self::assertSame('FALSE', $where);
    }

    public function testClippingExpressionsFollowOpenBounds(): void
    {
        $open = new StatisticsPeriodBounds(null);
        self::assertSame('ci.starts_at', ClosureIntervalSqlFilter::clippedStart($open));
        self::assertSame('ci.ends_at', ClosureIntervalSqlFilter::clippedEnd($open));
    }
}
