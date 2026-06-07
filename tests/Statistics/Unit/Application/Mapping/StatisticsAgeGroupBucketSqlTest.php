<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Mapping;

use App\Statistics\Application\Mapping\StatisticsAgeGroupBucketSql;
use PHPUnit\Framework\TestCase;

final class StatisticsAgeGroupBucketSqlTest extends TestCase
{
    public function testBucketKeysIncludeUnknownAfterDisplayBuckets(): void
    {
        self::assertNotContains('unknown', StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS);
        self::assertStringContainsString("THEN 'unknown'", StatisticsAgeGroupBucketSql::CASE_EXPRESSION);
        self::assertCount(
            \count(StatisticsAgeGroupBucketSql::DISPLAY_BUCKET_KEYS) + 1,
            StatisticsAgeGroupBucketSql::BUCKET_KEYS,
        );
    }
}
