<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\TopList\TopListLimitPolicy;
use PHPUnit\Framework\TestCase;

final class TopListLimitPolicyTest extends TestCase
{
    public function testNormalizesInputAndExposesAllowedValues(): void
    {
        $policy = new TopListLimitPolicy();

        self::assertSame([10, 25, 50], $policy->allowed());
        self::assertSame(25, $policy->default());
        self::assertSame(10, $policy->normalize('10'));
        self::assertSame(25, $policy->normalize('invalid'));
        self::assertSame(25, $policy->normalize(null));
    }
}
