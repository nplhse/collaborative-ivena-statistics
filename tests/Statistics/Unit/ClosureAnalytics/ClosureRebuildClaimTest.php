<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosureAnalytics;

use App\Statistics\ClosureAnalytics\Infrastructure\Projection\ClosureRebuildClaim;
use PHPUnit\Framework\TestCase;

final class ClosureRebuildClaimTest extends TestCase
{
    public function testEmptyClaimIsEmptyAndMergesAsIdentity(): void
    {
        $empty = ClosureRebuildClaim::empty();
        self::assertTrue($empty->isEmpty());
        self::assertFalse($empty->all);
        self::assertSame([], $empty->hospitalIds);

        $target = new ClosureRebuildClaim(false, [3, 1]);
        self::assertSame($target->hospitalIds, $empty->merge($target)->hospitalIds);
        self::assertSame($target->hospitalIds, $target->merge($empty)->hospitalIds);
    }

    public function testMergeCombinesHospitalIdsSortedAndDeduped(): void
    {
        $left = new ClosureRebuildClaim(false, [5, 2]);
        $right = new ClosureRebuildClaim(false, [2, 9, 1]);

        $merged = $left->merge($right);
        self::assertFalse($merged->all);
        self::assertSame([1, 2, 5, 9], $merged->hospitalIds);
    }

    public function testMergeWithAllProducesGlobalClaim(): void
    {
        $partial = new ClosureRebuildClaim(false, [4]);
        $all = new ClosureRebuildClaim(true, [99]);

        self::assertTrue($partial->merge($all)->all);
        self::assertSame([], $partial->merge($all)->hospitalIds);
        self::assertTrue($all->merge($partial)->all);
    }

    public function testTwoEmptyClaimsStayEmpty(): void
    {
        $merged = ClosureRebuildClaim::empty()->merge(ClosureRebuildClaim::empty());
        self::assertTrue($merged->isEmpty());
    }
}
