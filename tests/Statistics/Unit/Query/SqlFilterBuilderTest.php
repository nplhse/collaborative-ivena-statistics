<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Query;

use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Application\Panel\PanelFactory;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use PHPUnit\Framework\TestCase;

final class SqlFilterBuilderTest extends TestCase
{
    public function testBuildsWhereForLastTwelveMonthsPreset(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'last_12_months',
        ]), $panel);

        self::assertStringContainsString('created_at >= :date_from_default', $where['where']);
        self::assertCount(1, $where['params']);
    }

    public function testBuildsNoWhereForAllCases(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'all_cases',
        ]), $panel);

        self::assertSame('', $where['where']);
        self::assertSame([], $where['params']);
    }

    public function testBuildsExplicitDateRangeWhere(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = new PanelFactory()->createDistributionPanel('urgency');

        $where = $builder->buildWhere(new FilterState([
            'date_range' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
        ]), $panel);

        self::assertStringContainsString('created_at >= :date_from AND created_at <= :date_to', $where['where']);
        self::assertSame('2025-01-01 00:00:00', $where['params']['date_from']);
        self::assertSame('2025-01-31 23:59:59', $where['params']['date_to']);
    }
}
