<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Mapping;

use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use PHPUnit\Framework\TestCase;

final class StatisticsAgeGroupFilterTest extends TestCase
{
    public function testAggregateKeys(): void
    {
        self::assertContains(StatisticsAgeGroupFilter::UNDER_18, StatisticsAgeGroupFilter::AGGREGATE_KEYS);
        self::assertContains(StatisticsAgeGroupFilter::ADULTS, StatisticsAgeGroupFilter::AGGREGATE_KEYS);
        self::assertContains(StatisticsAgeGroupFilter::OVER_80, StatisticsAgeGroupFilter::AGGREGATE_KEYS);
    }

    public function testIsAggregate(): void
    {
        self::assertTrue(StatisticsAgeGroupFilter::isAggregate(StatisticsAgeGroupFilter::UNDER_18));
        self::assertTrue(StatisticsAgeGroupFilter::isAggregate(StatisticsAgeGroupFilter::ADULTS));
        self::assertTrue(StatisticsAgeGroupFilter::isAggregate(StatisticsAgeGroupFilter::OVER_80));
        self::assertFalse(StatisticsAgeGroupFilter::isAggregate('30_39'));
    }

    public function testSqlConditionForAggregateBuckets(): void
    {
        self::assertSame(
            'p.age IS NOT NULL AND p.age < 18',
            StatisticsAgeGroupFilter::sqlCondition('p.age', StatisticsAgeGroupFilter::UNDER_18),
        );
        self::assertSame(
            'p.age IS NOT NULL AND p.age >= 18',
            StatisticsAgeGroupFilter::sqlCondition('p.age', StatisticsAgeGroupFilter::ADULTS),
        );
        self::assertSame(
            'p.age >= 80',
            StatisticsAgeGroupFilter::sqlCondition('p.age', StatisticsAgeGroupFilter::OVER_80),
        );
    }

    public function testSqlConditionForDecadeBuckets(): void
    {
        self::assertSame(
            'p.age >= 30 AND p.age <= 39',
            StatisticsAgeGroupFilter::sqlCondition('p.age', '30_39'),
        );
        self::assertSame(
            'p.age IS NOT NULL AND p.age <= 17',
            StatisticsAgeGroupFilter::sqlCondition('p.age', '0_17'),
        );
        self::assertSame(
            'p.age >= 18 AND p.age <= 29',
            StatisticsAgeGroupFilter::sqlCondition('p.age', '18_29'),
        );
        self::assertSame(
            'p.age >= 90 AND p.age <= 99',
            StatisticsAgeGroupFilter::sqlCondition('p.age', '90_99'),
        );
    }

    public function testLegacyKeysMapToTheCurrentBuckets(): void
    {
        self::assertSame('0_17', StatisticsAgeGroupFilter::normalizeKey('0_18'));
        self::assertSame('18_29', StatisticsAgeGroupFilter::normalizeKey('19_29'));
        self::assertSame('30_39', StatisticsAgeGroupFilter::normalizeKey('30_39'));
        self::assertSame(StatisticsAgeGroupFilter::UNDER_18, StatisticsAgeGroupFilter::normalizeKey(StatisticsAgeGroupFilter::UNDER_18));

        self::assertSame(
            StatisticsAgeGroupFilter::sqlCondition('p.age', '0_17'),
            StatisticsAgeGroupFilter::sqlCondition('p.age', '0_18'),
        );
        self::assertSame(
            StatisticsAgeGroupFilter::sqlCondition('p.age', '18_29'),
            StatisticsAgeGroupFilter::sqlCondition('p.age', '19_29'),
        );
    }

    public function testSqlConditionReturnsNullForUnknownBucket(): void
    {
        self::assertNull(StatisticsAgeGroupFilter::sqlCondition('p.age', 'unknown'));
    }

    public function testBucketTranslationKey(): void
    {
        self::assertSame(
            'statistics.distribution.age.under_18',
            StatisticsAgeGroupFilter::bucketTranslationKey(StatisticsAgeGroupFilter::UNDER_18),
        );
        self::assertSame(
            'label.age_group.30_39',
            StatisticsAgeGroupFilter::bucketTranslationKey('30_39'),
        );
    }
}
