<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\TopList\TopListLimit;
use App\Statistics\Application\TopList\TopListLimitPolicy;
use PHPUnit\Framework\TestCase;

final class TopListLimitPolicyTest extends TestCase
{
    public function testNormalizesInputAndExposesAllowedValues(): void
    {
        $policy = new TopListLimitPolicy();
        $allowed = array_map(
            static fn (TopListLimit $limit): int|string => $limit->queryValue(),
            $policy->allowed(),
        );

        self::assertSame([10, 25, 50, 100, 'all'], $allowed);
        self::assertSame(25, $policy->default()->queryValue());
        self::assertSame(10, $policy->normalize('10')->queryValue());
        self::assertSame(100, $policy->normalize('100')->queryValue());
        self::assertSame('all', $policy->normalize('all')->queryValue());
        self::assertSame('all', $policy->normalize('ALL')->queryValue());
        self::assertSame(25, $policy->normalize('invalid')->queryValue());
        self::assertSame(25, $policy->normalize(null)->queryValue());
        self::assertTrue($policy->normalize('all')->isAll);
        self::assertSame(TopListLimit::ALL_SAFETY_CAP + 1, $policy->normalize('all')->queryLimit());
    }
}
