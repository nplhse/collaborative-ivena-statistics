<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Infrastructure\Csv;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\DataFixtures\Generator\Application\PlannedClosure;

/**
 * Formats planned closures as rows of an IVENA closure list, as the closure import reads it
 * (`ClosureRowMapper`, docs/04-features/import/closure-import.md). The remark column carries
 * `IvenaCsvFile::SYNTHETIC_REMARK` (#791).
 */
final readonly class IvenaClosureCsv
{
    public const array HEADER = [
        'Krankenhaus-Kurzname', 'Schließungseinheit', 'Fachgebiet', 'Fachbereich', 'Behandlungsdringlichkeit',
        'Datum (Schließungs-Beginn)', 'Uhrzeit (Schließungs-Beginn)', 'Schließungs-Dauer (Minuten)',
        'Datum (Schließungs-Ende)', 'Uhrzeit (Schließungs-Ende)', 'Grund', 'Gruppen-Schließungs-ID', 'Bemerkung',
        'Krankenhausinterne Bemerkung', 'Eingetragen am', 'Geändert am', 'Typ',
    ];

    /**
     * @param string $hospitalShort short hospital name of the export; the closure import compares it with the selected hospital
     */
    public function __construct(
        private string $hospitalShort,
    ) {
    }

    /**
     * @param string $speciality catalog name of the department's speciality
     * @param string $department catalog name of the department
     *
     * @return list<string>
     */
    public function row(PlannedClosure $closure, string $speciality, string $department): array
    {
        $minutes = intdiv($closure->endsAt->getTimestamp() - $closure->startsAt->getTimestamp(), 60);

        return [
            $this->hospitalShort,
            '',
            $speciality,
            $department,
            match ($closure->careLevel) {
                ClosureCareLevel::EMERGENCY => 'Notfallversorgung',
                ClosureCareLevel::INPATIENT => 'Stationäre Versorgung',
                ClosureCareLevel::OUTPATIENT => 'Ambulante Versorgung',
                ClosureCareLevel::OTHER => 'Sonstige',
            },
            $closure->startsAt->format('d.m.Y'),
            $closure->startsAt->format('H:i:s'),
            (string) $minutes,
            $closure->endsAt->format('d.m.Y'),
            $closure->endsAt->format('H:i:s'),
            match ($closure->reason) {
                ClosureReason::EMERGENCY_DEPARTMENT_OVERLOAD => 'Überlastung der Notaufnahme',
                ClosureReason::NO_BED_CAPACITY => 'keine Bettenkapazitäten',
                ClosureReason::TECHNICAL_FAULT => 'Technische Störung',
                ClosureReason::OPERATING_ROOM_NOTICE => 'OP-Meldung',
                ClosureReason::NOT_SPECIFIED => 'k.A.',
            },
            (string) $closure->sourceGroupId,
            IvenaCsvFile::SYNTHETIC_REMARK,
            '',
            $closure->startsAt->format('d.m.Y H:i:s'),
            $closure->startsAt->format('d.m.Y H:i:s'),
            'Klinik',
        ];
    }
}
