<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\AgeCohortBucketExpression;
use PHPUnit\Framework\TestCase;

final class AgeCohortBucketExpressionTest extends TestCase
{
    public function testSqlContainsExpectedBranches(): void
    {
        $sql = AgeCohortBucketExpression::sql('age');

        self::assertStringContainsString('WHEN age IS NULL THEN -1', $sql);
        self::assertStringContainsString('WHEN age < 18 THEN 0', $sql);
        self::assertStringContainsString('WHEN age < 30 THEN 1', $sql);
        self::assertStringContainsString('WHEN age < 90 THEN 7', $sql);
        self::assertStringContainsString('ELSE 8 END', $sql);
    }

    public function testOrderedBucketCodes(): void
    {
        self::assertSame([-1, 0, 1, 2, 3, 4, 5, 6, 7, 8], AgeCohortBucketExpression::orderedBucketCodes());
    }
}
