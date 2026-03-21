<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use PHPUnit\Framework\TestCase;

final class AllocationStatsGenderProjectionCodeTest extends TestCase
{
    public function testMapsDbLettersToStableInts(): void
    {
        self::assertSame(AllocationStatsGenderProjectionCode::Male, AllocationStatsGenderProjectionCode::tryFromDbLetter('M'));
        self::assertSame(AllocationStatsGenderProjectionCode::Female, AllocationStatsGenderProjectionCode::tryFromDbLetter('f'));
        self::assertSame(AllocationStatsGenderProjectionCode::Other, AllocationStatsGenderProjectionCode::tryFromDbLetter('x'));
        self::assertSame(1, AllocationStatsGenderProjectionCode::tryFromDbLetter('M')->value);
        self::assertNull(AllocationStatsGenderProjectionCode::tryFromDbLetter(null));
        self::assertNull(AllocationStatsGenderProjectionCode::tryFromDbLetter(''));
        self::assertNull(AllocationStatsGenderProjectionCode::tryFromDbLetter('W'));
    }
}
