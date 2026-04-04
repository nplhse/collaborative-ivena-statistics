<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Query;

use App\Statistics\Application\Filter\FilterDefinition;
use App\Statistics\Application\Filter\FilterRegistry;
use App\Statistics\Application\Filter\FilterState;
use App\Statistics\Infrastructure\Query\SqlFilterBuilder;
use App\Tests\Statistics\Fixtures\DistributionPanelFixtures;
use PHPUnit\Framework\TestCase;

final class SqlFilterBuilderTest extends TestCase
{
    public function testBuildsWhereForLastTwelveMonthsPreset(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'last_12_months',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]), $panel);

        self::assertStringContainsString('created_at >= :date_from_default', $where['where']);
        self::assertCount(1, $where['params']);
    }

    public function testBuildsNoWhereForAllCases(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]), $panel);

        self::assertSame('', $where['where']);
        self::assertSame([], $where['params']);
    }

    public function testBuildsExplicitDateRangeWhere(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();

        $where = $builder->buildWhere(new FilterState([
            'date_range' => ['from' => '2025-01-01', 'to' => '2025-01-31'],
            'hospital_tier' => [],
            'hospital_location' => [],
        ]), $panel);

        self::assertStringContainsString('created_at >= :date_from AND created_at <= :date_to', $where['where']);
        self::assertSame('2025-01-01 00:00:00', $where['params']['date_from']);
        self::assertSame('2025-01-31 23:59:59', $where['params']['date_to']);
    }

    public function testDateRangeUsesFilterDefinitionField(): void
    {
        $registry = new FilterRegistry();
        $ref = new \ReflectionClass($registry);
        $prop = $ref->getProperty('definitions');
        /** @var array<string, FilterDefinition> $defs */
        $defs = $prop->getValue($registry);
        $defs['date_range'] = new FilterDefinition(
            key: 'date_range',
            type: 'date_range',
            field: 'arrival_at',
            defaultValue: 'all_cases',
        );
        $prop->setValue($registry, $defs);

        $builder = new SqlFilterBuilder($registry);
        $panel = DistributionPanelFixtures::urgency();

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'last_12_months',
            'hospital_tier' => [],
            'hospital_location' => [],
        ]), $panel);

        self::assertStringContainsString('arrival_at >= :date_from_default', $where['where']);
    }

    public function testHospitalTierInClause(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [1, 3],
            'hospital_location' => [],
        ]), $panel);

        self::assertStringContainsString('hospital_tier_code IN (:hospital_tier_code_0, :hospital_tier_code_1)', $where['where']);
        self::assertSame(1, $where['params']['hospital_tier_code_0']);
        self::assertSame(3, $where['params']['hospital_tier_code_1']);
    }

    public function testHospitalLocationInClause(): void
    {
        $builder = new SqlFilterBuilder(new FilterRegistry());
        $panel = DistributionPanelFixtures::urgency();

        $where = $builder->buildWhere(new FilterState([
            'date_range' => 'all_cases',
            'hospital_tier' => [],
            'hospital_location' => [2, 3],
        ]), $panel);

        self::assertStringContainsString('hospital_location_code IN (:hospital_location_code_0, :hospital_location_code_1)', $where['where']);
        self::assertSame(2, $where['params']['hospital_location_code_0']);
        self::assertSame(3, $where['params']['hospital_location_code_1']);
    }
}
