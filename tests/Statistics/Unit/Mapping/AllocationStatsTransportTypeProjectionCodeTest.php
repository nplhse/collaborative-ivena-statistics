<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use PHPUnit\Framework\TestCase;

final class AllocationStatsTransportTypeProjectionCodeTest extends TestCase
{
    public function testMapsDbLettersToStableInts(): void
    {
        self::assertSame(AllocationStatsTransportTypeProjectionCode::Ground, AllocationStatsTransportTypeProjectionCode::tryFromDbLetter('G'));
        self::assertSame(AllocationStatsTransportTypeProjectionCode::Air, AllocationStatsTransportTypeProjectionCode::tryFromDbLetter('a'));
        self::assertSame(1, AllocationStatsTransportTypeProjectionCode::tryFromDbLetter('G')->value);
        self::assertNull(AllocationStatsTransportTypeProjectionCode::tryFromDbLetter(null));
        self::assertNull(AllocationStatsTransportTypeProjectionCode::tryFromDbLetter(''));
        self::assertNull(AllocationStatsTransportTypeProjectionCode::tryFromDbLetter('X'));
    }
}
