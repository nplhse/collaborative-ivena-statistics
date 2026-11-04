<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Mapping;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Allocation\Domain\Service\ClosureIntervalDuration;
use App\Import\Application\Exception\ImportException;
use App\Import\Application\Mapping\ClosureCareLevelCatalog;
use App\Import\Application\Mapping\ClosureFacilityKindCatalog;
use App\Import\Application\Mapping\ClosureIntervalClock;
use App\Import\Application\Mapping\ClosureReasonCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClosureCatalogAndClockTest extends TestCase
{
    public function testCareLevelCatalogMapsSkAndSonstige(): void
    {
        $catalog = new ClosureCareLevelCatalog();

        self::assertSame(ClosureCareLevel::EMERGENCY, $catalog->resolve('Notfallversorgung'));
        self::assertSame(ClosureCareLevel::INPATIENT, $catalog->resolve('Stationäre Versorgung'));
        self::assertSame(ClosureCareLevel::OUTPATIENT, $catalog->resolve('Ambulante Versorgung'));
        self::assertSame(ClosureCareLevel::OTHER, $catalog->resolve('Sonstige'));
        self::assertNull(ClosureCareLevel::OTHER->toAllocationUrgency());
        self::assertSame('SK1', ClosureCareLevel::EMERGENCY->skLabel());
        self::assertSame(AllocationUrgency::INPATIENT, ClosureCareLevel::INPATIENT->toAllocationUrgency());
        self::assertSame(AllocationUrgency::OUTPATIENT, ClosureCareLevel::OUTPATIENT->toAllocationUrgency());
        self::assertSame('SK2', ClosureCareLevel::INPATIENT->skLabel());
        self::assertNull(ClosureCareLevel::OTHER->skLabel());
    }

    public function testReasonCatalogKeepsNotSpecified(): void
    {
        $catalog = new ClosureReasonCatalog();

        self::assertSame(ClosureReason::NOT_SPECIFIED, $catalog->resolve('k.A.'));
        self::assertSame(ClosureReason::EMERGENCY_DEPARTMENT_OVERLOAD, $catalog->resolve('Überlastung der Notaufnahme'));
        self::assertSame(ClosureReason::NO_BED_CAPACITY, $catalog->resolve('keine Bettenkapazitäten'));
        self::assertSame(ClosureReason::TECHNICAL_FAULT, $catalog->resolve('Technische Störung'));
        self::assertSame(ClosureReason::OPERATING_ROOM_NOTICE, $catalog->resolve('OP-Meldung'));
    }

    public function testUnknownReasonIsRejected(): void
    {
        $this->expectException(ImportException::class);
        new ClosureReasonCatalog()->resolve('Nicht im Katalog');
    }

    public function testFacilityKindRejectsUnknown(): void
    {
        $this->expectException(ImportException::class);
        new ClosureFacilityKindCatalog()->resolve('Praxis');
    }

    public function testClockAcceptsDstElapsedDuration(): void
    {
        $times = new ClosureIntervalClock()->resolve(
            '28.03.2026',
            '09:50:00',
            '29.03.2026',
            '08:00:00',
            1270,
            '28.03.2026 09:55:00',
            '28.03.2026 09:55:00',
        );

        self::assertSame(1270, ClosureIntervalDuration::minutesBetween($times['startsAt'], $times['endsAt']));
    }

    /**
     * IVENA reports some closures across a DST switch with the difference of the clock
     * times. The stored duration stays the elapsed time.
     *
     * @param int $declared IVENA duration
     * @param int $elapsed  derived from start and end
     */
    #[DataProvider('provideWallClockDurationsAcrossDst')]
    public function testClockAcceptsWallClockDurationAcrossDst(
        string $startsOn,
        string $startsAt,
        string $endsOn,
        string $endsAt,
        int $declared,
        int $elapsed,
    ): void {
        $times = new ClosureIntervalClock()->resolve($startsOn, $startsAt, $endsOn, $endsAt, $declared, $startsOn.' '.$startsAt, $startsOn.' '.$startsAt);

        self::assertSame($elapsed, ClosureIntervalDuration::minutesBetween($times['startsAt'], $times['endsAt']));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int}>
     */
    public static function provideWallClockDurationsAcrossDst(): iterable
    {
        // 07:30 CET to 08:30 CEST: 25 h on the clock, 24 h elapsed.
        yield 'spring, overnight 24 h closure' => ['28.03.2026', '07:30:00', '29.03.2026', '08:30:00', 1500, 1440];
        // 09:50 CET to 08:00 CEST: 22 h 10 min on the clock, 21 h 10 min elapsed.
        yield 'spring, overnight to a fixed end' => ['28.03.2026', '09:50:00', '29.03.2026', '08:00:00', 1330, 1270];
        // 01:30 CET to 03:30 CEST: 2 h on the clock, 1 h elapsed.
        yield 'spring, inside the night' => ['29.03.2026', '01:30:00', '29.03.2026', '03:30:00', 120, 60];
        // 12:00 CEST to 12:00 CET: 24 h on the clock, 25 h elapsed.
        yield 'autumn, overnight' => ['24.10.2026', '12:00:00', '25.10.2026', '12:00:00', 1440, 1500];
    }

    public function testClockStillAcceptsElapsedDurationInAutumn(): void
    {
        $times = new ClosureIntervalClock()->resolve('24.10.2026', '12:00:00', '25.10.2026', '12:00:00', 1500, '24.10.2026 12:00:00', '24.10.2026 12:00:00');

        self::assertSame(1500, ClosureIntervalDuration::minutesBetween($times['startsAt'], $times['endsAt']));
    }

    public function testClockRejectsDurationThatMatchesNeitherElapsedNorClockTime(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Closure duration 1400 does not match elapsed minutes 1440 or clock difference 1500');

        new ClosureIntervalClock()->resolve('28.03.2026', '07:30:00', '29.03.2026', '08:30:00', 1400, '28.03.2026 07:30:00', '28.03.2026 07:30:00');
    }

    public function testClockRejectsEndThatIsNotAfterStart(): void
    {
        $this->expectException(ImportException::class);
        new ClosureIntervalClock()->resolve(
            '01.01.2026',
            '10:00:00',
            '01.01.2026',
            '09:00:00',
            60,
            '01.01.2026 10:00:00',
            '01.01.2026 10:00:00',
        );
    }

    public function testClockRejectsUnparseableTimestamp(): void
    {
        $this->expectException(ImportException::class);
        new ClosureIntervalClock()->resolve(
            '32.01.2026',
            '10:00:00',
            '01.01.2026',
            '11:00:00',
            60,
            '01.01.2026 10:00:00',
            '01.01.2026 10:00:00',
        );
    }
}
