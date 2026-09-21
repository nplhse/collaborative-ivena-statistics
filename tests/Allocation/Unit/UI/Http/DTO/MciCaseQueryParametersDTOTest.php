<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\UI\Http\DTO;

use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use PHPUnit\Framework\TestCase;

final class MciCaseQueryParametersDTOTest extends TestCase
{
    public function testBlankSearchAndMciIdAreInactive(): void
    {
        $query = new MciCaseQueryParametersDTO(mciId: '  ', search: '');

        self::assertNull($query->normalizedMciId());
        self::assertNull($query->normalizedSearch());
        self::assertFalse($query->hasArrivalAtRange());
    }

    public function testArrivalRangeUsesInclusiveCalendarDays(): void
    {
        $query = new MciCaseQueryParametersDTO(
            arrivalFrom: '2024-01-01',
            arrivalUntil: '2024-01-31',
        );

        self::assertTrue($query->hasArrivalAtRange());
        self::assertSame('2024-01-01', $query->arrivalFromDate());
        self::assertSame('2024-01-31', $query->arrivalUntilDate());
        self::assertEquals(new \DateTimeImmutable('2024-01-01 00:00:00'), $query->arrivalAtRange()->from);
        self::assertEquals(new \DateTimeImmutable('2024-02-01 00:00:00'), $query->arrivalAtRange()->toExclusive);
    }
}
