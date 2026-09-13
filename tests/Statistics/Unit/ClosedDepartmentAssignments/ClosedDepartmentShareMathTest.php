<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\ClosedDepartmentAssignments;

use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentShareMath;
use PHPUnit\Framework\TestCase;

final class ClosedDepartmentShareMathTest extends TestCase
{
    public function testPercentReturnsNullForEmptyDenominator(): void
    {
        self::assertNull(ClosedDepartmentShareMath::percent(0, 0));
        self::assertNull(ClosedDepartmentShareMath::percent(5, 0));
        self::assertSame(0.0, ClosedDepartmentShareMath::percent(0, 10));
        self::assertSame(17.0, ClosedDepartmentShareMath::percent(17, 100));
    }

    public function testDeltaPpRequiresBothPercents(): void
    {
        self::assertNull(ClosedDepartmentShareMath::deltaPp(null, 8.0));
        self::assertNull(ClosedDepartmentShareMath::deltaPp(17.0, null));
        self::assertSame(9.0, ClosedDepartmentShareMath::deltaPp(17.0, 8.0));
    }

    public function testDeltaMinutes(): void
    {
        self::assertNull(ClosedDepartmentShareMath::deltaMinutes(null, 18.0));
        self::assertSame(6.0, ClosedDepartmentShareMath::deltaMinutes(24.0, 18.0));
    }
}
