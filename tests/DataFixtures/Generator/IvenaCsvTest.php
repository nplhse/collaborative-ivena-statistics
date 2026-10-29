<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\DataFixtures\Generator\Application\PlannedClosure;
use App\DataFixtures\Generator\Application\SampledAllocation;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaAllocationCsv;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaClosureCsv;
use App\DataFixtures\Generator\Infrastructure\Csv\IvenaCsvFile;
use App\DataFixtures\Pattern\Dto\SampledAllocationAttributes;
use PHPUnit\Framework\TestCase;

/**
 * Rows of the demo IVENA files (#791) in the notation the import reads.
 */
final class IvenaCsvTest extends TestCase
{
    public function testAnAllocationRowUsesTheIvenaNotation(): void
    {
        $csv = new IvenaAllocationCsv('Kassel', 'Klinikum Nord, Weg 1, 34117 Kassel', 'Klinikum Nord', 'SYNTH-740-abcd1234-2026-08');
        $sampled = new SampledAllocationAttributes(
            createdAt: new \DateTimeImmutable('2026-08-03 09:05:00'),
            arrivalAt: new \DateTimeImmutable('2026-08-03 09:31:00'),
            gender: AllocationGender::FEMALE,
            age: 7,
            urgency: AllocationUrgency::INPATIENT,
            transportType: AllocationTransportType::AIR,
            speciality: new Speciality()->setName('Innere Medizin'),
            department: new Department()->setName('Kardiologie'),
            assignment: new Assignment()->setName('RD'),
            occasion: new Occasion()->setName('Häuslicher Einsatz'),
            indicationRaw: new IndicationRaw()->setCode(42)->setName('Brustschmerz'),
            indicationNormalized: null,
            infection: null,
            secondaryTransport: new SecondaryTransport()->setName('Kapazitätsengpass'),
            requiresResus: true,
            requiresCathlab: false,
            isCpr: false,
            isVentilated: true,
            isShock: false,
            isPregnant: true,
            isWorkAccident: false,
            isWithPhysician: true,
        );

        self::assertSame([
            'Kassel', '1', 'Klinikum Nord, Weg 1, 34117 Kassel', 'Klinikum Nord',
            '03.08.2026', '09:31', 'W', '7',
            'S+', 'H-', 'R-', 'B+', 'nein', 'Schwanger', 'N+', 'nein', 'Luft',
            '03.08.2026', '09:05',
            '042072', 'Innere Medizin', 'Kardiologie', 'Ja', 'Häuslicher Einsatz', 'Kapazitätsengpass', 'RD',
            'Keine', '042 Brustschmerz', 'SYNTH-740-abcd1234-2026-08-12', 'SYNTHETIC DEMO DATA (#791)',
        ], $csv->row(new SampledAllocation($sampled, true), 12));
        self::assertCount(\count(IvenaAllocationCsv::HEADER), $csv->row(new SampledAllocation($sampled, true), 1));

        $infected = new SampledAllocationAttributes(...[...get_object_vars($sampled), 'infection' => new Infection()->setName('MRSA'), 'gender' => AllocationGender::OTHER, 'transportType' => null]);
        $row = $csv->row(new SampledAllocation($infected, false), 1);
        self::assertSame(['D', '', 'Nein', 'MRSA'], [$row[6], $row[16], $row[22], $row[26]]);
    }

    public function testAClosureRowUsesTheIvenaNotation(): void
    {
        $closure = new PlannedClosure('cardiology', new \DateTimeImmutable('2026-08-03 22:15:00'), new \DateTimeImmutable('2026-08-04 01:45:00'), ClosureCareLevel::INPATIENT, ClosureReason::NO_BED_CAPACITY, '17');

        $row = new IvenaClosureCsv('Klinikum Nord')->row($closure, 'Innere Medizin', 'Kardiologie');

        self::assertSame([
            'Klinikum Nord', '', 'Innere Medizin', 'Kardiologie', 'Stationäre Versorgung',
            '03.08.2026', '22:15:00', '210', '04.08.2026', '01:45:00', 'keine Bettenkapazitäten', '17',
            'SYNTHETIC DEMO DATA (#791)', '', '03.08.2026 22:15:00', '03.08.2026 22:15:00', 'Klinik',
        ], $row);
        self::assertCount(\count(IvenaClosureCsv::HEADER), $row);
    }

    public function testEveryFieldIsQuoted(): void
    {
        self::assertSame("\"a\";\"b \"\"c\"\"\";\"\"\n", IvenaCsvFile::line(['a', 'b "c"', '']));
    }
}
