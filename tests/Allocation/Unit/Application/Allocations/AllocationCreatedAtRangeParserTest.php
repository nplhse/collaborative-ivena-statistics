<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Allocations;

use App\Allocation\Application\Allocations\AllocationCreatedAtRangeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AllocationCreatedAtRangeParserTest extends TestCase
{
    public function testParsesInclusiveCalendarDatesToHalfOpenBounds(): void
    {
        $range = new AllocationCreatedAtRangeParser()->parse('2025-01-01', '2025-12-31');

        self::assertEquals(new \DateTimeImmutable('2025-01-01 00:00:00'), $range->from);
        self::assertEquals(new \DateTimeImmutable('2026-01-01 00:00:00'), $range->toExclusive);
        self::assertSame('2025-01-01', $range->fromDate);
        self::assertSame('2025-12-31', $range->untilDate);
        self::assertTrue($range->isActive());
    }

    public function testParsesLegacyExclusiveDatetimeIntoInclusiveUntilDate(): void
    {
        $range = new AllocationCreatedAtRangeParser()->parse(
            '2026-03-01T00:00:00',
            null,
            '2026-04-01T00:00:00',
        );

        self::assertEquals(new \DateTimeImmutable('2026-03-01 00:00:00'), $range->from);
        self::assertEquals(new \DateTimeImmutable('2026-04-01 00:00:00'), $range->toExclusive);
        self::assertSame('2026-03-01', $range->fromDate);
        self::assertSame('2026-03-31', $range->untilDate);
    }

    public function testCreatedUntilWinsOverLegacyExclusiveEnd(): void
    {
        $range = new AllocationCreatedAtRangeParser()->parse(
            '2025-01-01',
            '2025-06-30',
            '2026-01-01T00:00:00',
        );

        self::assertEquals(new \DateTimeImmutable('2025-07-01 00:00:00'), $range->toExclusive);
        self::assertSame('2025-06-30', $range->untilDate);
    }

    #[DataProvider('openBoundCases')]
    public function testAllowsOpenBounds(
        ?string $createdFrom,
        ?string $createdUntil,
        ?string $createdToExclusive,
        ?string $expectedFromDate,
        ?string $expectedUntilDate,
        ?string $expectedFrom,
        ?string $expectedToExclusive,
    ): void {
        $range = new AllocationCreatedAtRangeParser()->parse($createdFrom, $createdUntil, $createdToExclusive);

        self::assertSame($expectedFromDate, $range->fromDate);
        self::assertSame($expectedUntilDate, $range->untilDate);
        self::assertSame($expectedFrom, $range->from?->format('Y-m-d H:i:s'));
        self::assertSame($expectedToExclusive, $range->toExclusive?->format('Y-m-d H:i:s'));
        self::assertTrue($range->isActive());
    }

    /**
     * @return iterable<string, array{
     *     0: ?string,
     *     1: ?string,
     *     2: ?string,
     *     3: ?string,
     *     4: ?string,
     *     5: ?string,
     *     6: ?string
     * }>
     */
    public static function openBoundCases(): iterable
    {
        yield 'from only' => [
            '2025-01-01',
            null,
            null,
            '2025-01-01',
            null,
            '2025-01-01 00:00:00',
            null,
        ];
        yield 'until only' => [
            null,
            '2025-12-31',
            null,
            null,
            '2025-12-31',
            null,
            '2026-01-01 00:00:00',
        ];
        yield 'legacy exclusive only' => [
            null,
            null,
            '2026-01-01T00:00:00',
            null,
            '2025-12-31',
            null,
            '2026-01-01 00:00:00',
        ];
    }

    public function testBlankAndInvalidValuesAreInactive(): void
    {
        $parser = new AllocationCreatedAtRangeParser();

        self::assertFalse($parser->parse(null, null)->isActive());
        self::assertFalse($parser->parse('', '  ')->isActive());
        self::assertFalse($parser->parse('not-a-date', 'also-bad')->isActive());
    }

    public function testNonMidnightExclusiveEndKeepsThatCalendarDay(): void
    {
        self::assertSame(
            '2026-03-15',
            AllocationCreatedAtRangeParser::inclusiveDateFromExclusiveEnd(
                new \DateTimeImmutable('2026-03-15 14:30:00'),
            ),
        );
    }
}
