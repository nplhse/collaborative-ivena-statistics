<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\TopList\TopListPageSizePolicy;
use PHPUnit\Framework\TestCase;

final class TopListPageSizePolicyTest extends TestCase
{
    public function testNormalizesInputAndExposesAllowedValues(): void
    {
        $policy = new TopListPageSizePolicy();

        self::assertSame([25, 50, 100], $policy->allowed());
        self::assertSame(25, $policy->default());
        self::assertSame(25, $policy->normalize('25'));
        self::assertSame(50, $policy->normalize('50'));
        self::assertSame(100, $policy->normalize(100));
        self::assertSame(25, $policy->normalize('10'));
        self::assertSame(25, $policy->normalize('all'));
        self::assertSame(25, $policy->normalize('invalid'));
        self::assertSame(25, $policy->normalize(null));
        self::assertSame(25, $policy->normalize(''));
    }
}
