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
use App\Import\Application\Mapping\ClosureHospitalGuard;
use App\Import\Application\Mapping\ClosureIntervalClock;
use App\Import\Application\Mapping\ClosureReasonCatalog;
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

    public function testClockRejectsWallClockDurationAcrossDst(): void
    {
        $this->expectException(ImportException::class);
        new ClosureIntervalClock()->resolve(
            '28.03.2026',
            '09:50:00',
            '29.03.2026',
            '08:00:00',
            1330,
            '28.03.2026 09:55:00',
            '28.03.2026 09:55:00',
        );
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

    public function testHospitalGuardComparesCollapsedNames(): void
    {
        $guard = new ClosureHospitalGuard();
        $guard->assertMatches('Klinikum Beispiel', '  klinikum   beispiel ');

        $this->expectException(ImportException::class);
        $guard->assertMatches('Klinikum Beispiel', 'Andere Klinik');
    }
}
