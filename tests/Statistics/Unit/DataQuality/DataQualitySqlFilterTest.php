<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\DataQuality;

use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\DataQuality\Infrastructure\Query\DataQualitySqlFilter;
use PHPUnit\Framework\TestCase;

final class DataQualitySqlFilterTest extends TestCase
{
    public function testBuildWhereIncludesIndicationFilterWhenIdProvided(): void
    {
        [$where, $params] = DataQualitySqlFilter::buildWhere(
            42,
            null,
            null,
            StatisticsScopeCriteria::public(),
        );

        self::assertStringContainsString('indication_normalized_id = :indication_id', $where);
        self::assertSame(42, $params['indication_id']);
    }

    public function testBuildWhereOmitsIndicationFilterWhenIdIsNull(): void
    {
        [$where, $params] = DataQualitySqlFilter::buildWhere(
            null,
            null,
            null,
            StatisticsScopeCriteria::public(),
        );

        self::assertStringNotContainsString('indication_normalized_id', $where);
        self::assertArrayNotHasKey('indication_id', $params);
    }

    public function testBuildWhereIncludesPeriodBounds(): void
    {
        $from = new \DateTimeImmutable('2024-01-01 00:00:00');
        $toExclusive = new \DateTimeImmutable('2024-02-01 00:00:00');

        [$where, $params] = DataQualitySqlFilter::buildWhere(
            null,
            $from,
            $toExclusive,
            StatisticsScopeCriteria::public(),
        );

        self::assertStringContainsString('created_at >= :from', $where);
        self::assertStringContainsString('created_at < :to_exclusive', $where);
        self::assertSame('2024-01-01 00:00:00', $params['from']);
        self::assertSame('2024-02-01 00:00:00', $params['to_exclusive']);
    }
}
