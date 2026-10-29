<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Csv;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\DataFixtures\Generator\Application\SampledAllocation;

/**
 * Formats sampled allocations as rows of an IVENA allocation export, as the import reads it
 * (`AllocationRowMapper`, docs/04-features/import/import-pipeline.md).
 *
 * Every row says it is synthetic: the case number starts with `SYNTH-` and the free text column carries
 * `IvenaCsvFile::SYNTHETIC_REMARK` (#791).
 */
final readonly class IvenaAllocationCsv
{
    public const array HEADER = [
        'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
        'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)', 'Geschlecht', 'Alter',
        'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet', 'Schock', 'Schwanger', 'Arztbegleitet',
        'Arbeits-/Wege-/Schulunfall', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
        'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Sekundäranlass', 'Grund',
        'Ansteckungsfähig', 'PZC und Text', 'ENR', 'Freitext',
    ];

    /**
     * @param string $dispatchArea  name of the hospital's dispatch area
     * @param string $hospitalLabel long hospital label of the export (name and address)
     * @param string $hospitalShort short hospital name of the export
     * @param string $casePrefix    case numbers are `<prefix>-<running number>`
     */
    public function __construct(
        private string $dispatchArea,
        private string $hospitalLabel,
        private string $hospitalShort,
        private string $casePrefix,
    ) {
    }

    /**
     * @return list<string>
     */
    public function row(SampledAllocation $allocation, int $number): array
    {
        $a = $allocation->attributes;
        $code = sprintf('%03d', (int) $a->indicationRaw->getCode());

        return [
            $this->dispatchArea,
            '1',
            $this->hospitalLabel,
            $this->hospitalShort,
            $a->arrivalAt->format('d.m.Y'),
            $a->arrivalAt->format('H:i'),
            match ($a->gender) {
                AllocationGender::MALE => 'M',
                AllocationGender::FEMALE => 'W',
                AllocationGender::OTHER => 'D',
            },
            (string) $a->age,
            $a->requiresResus ? 'S+' : 'S-',
            $a->requiresCathlab ? 'H+' : 'H-',
            $a->isCpr ? 'R+' : 'R-',
            $a->isVentilated ? 'B+' : 'B-',
            $a->isShock ? 'ja' : 'nein',
            $a->isPregnant ? 'Schwanger' : '',
            $a->isWithPhysician ? 'N+' : 'N-',
            $a->isWorkAccident ? 'ja' : 'nein',
            match ($a->transportType) {
                AllocationTransportType::GROUND => 'Boden',
                AllocationTransportType::AIR => 'Luft',
                null => '',
            },
            $a->createdAt->format('d.m.Y'),
            $a->createdAt->format('H:i'),
            // PZC: three-digit code, two-digit age, urgency.
            sprintf('%s%02d%d', $code, min(99, max(0, $a->age)), $a->urgency->value),
            (string) $a->speciality->getName(),
            (string) $a->department->getName(),
            $allocation->departmentWasClosed ? 'Ja' : 'Nein',
            (string) $a->occasion->getName(),
            (string) $a->secondaryTransport?->getName(),
            (string) $a->assignment->getName(),
            $a->infection?->getName() ?? 'Keine',
            sprintf('%s %s', $code, (string) $a->indicationRaw->getName()),
            sprintf('%s-%d', $this->casePrefix, $number),
            IvenaCsvFile::SYNTHETIC_REMARK,
        ];
    }
}
