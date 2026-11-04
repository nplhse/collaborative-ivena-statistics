<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Mapping;

use App\Import\Application\Exception\ImportException;
use App\Import\Infrastructure\Mapping\ClosureRowMapper;
use PHPUnit\Framework\TestCase;

final class ClosureRowMapperTest extends TestCase
{
    public function testMapsClosureColumnsAndDropsEmptyValues(): void
    {
        $dto = new ClosureRowMapper()->mapAssoc([
            'krankenhaus_kurzname' => ' Klinikum Beispiel ',
            'fachgebiet' => 'Innere Medizin',
            'fachbereich' => 'Kardiologie',
            'behandlungsdringlichkeit' => 'Notfallversorgung',
            'grund' => 'k.A.',
            'typ' => 'Klinik',
            'datum_schliessungs_beginn' => '01.01.2026',
            'uhrzeit_schliessungs_beginn' => '00:10:00',
            'datum_schliessungs_ende' => '01.01.2026',
            'uhrzeit_schliessungs_ende' => '01:10:00',
            'schliessungs_dauer_minuten' => '60',
            'schliessungseinheit' => '',
            'gruppen_schliessungs_id' => '88759901',
            'bemerkung' => 'Hinweis',
            'krankenhausinterne_bemerkung' => '',
            'eingetragen_am' => '01.01.2026 00:20:22',
            'geaendert_am' => '01.01.2026 00:20:22',
        ]);

        self::assertSame('Klinikum Beispiel', $dto->hospitalShortName);
        self::assertSame('Innere Medizin', $dto->speciality);
        self::assertSame('Kardiologie', $dto->department);
        self::assertSame(60, $dto->durationMinutes);
        self::assertNull($dto->closureUnit);
        self::assertSame('88759901', $dto->sourceGroupId);
        self::assertSame('Hinweis', $dto->remark);
        self::assertNull($dto->internalRemark);
    }

    public function testDurationThatIsNotDigitsBecomesNull(): void
    {
        $dto = new ClosureRowMapper()->mapAssoc([
            'schliessungs_dauer_minuten' => '12a',
        ]);

        self::assertNull($dto->durationMinutes);
        self::assertNull($dto->hospitalShortName);
    }

    public function testMinutePrecisionTimesDefaultToZeroSeconds(): void
    {
        $dto = new ClosureRowMapper()->mapAssoc([
            'uhrzeit_schliessungs_beginn' => '11:40',
            'uhrzeit_schliessungs_ende' => '13:00:00',
            'eingetragen_am' => '23.09.2026 11:50',
            'geaendert_am' => '23.09.2026 11:50:07',
        ]);

        self::assertSame('11:40:00', $dto->startsAtTime);
        self::assertSame('13:00:00', $dto->endsAtTime);
        self::assertSame('23.09.2026 11:50:00', $dto->sourceRecordedAt);
        self::assertSame('23.09.2026 11:50:07', $dto->sourceChangedAt);
    }

    public function testShortYearsAndUnpaddedDateTimesBecomeCanonical(): void
    {
        $dto = new ClosureRowMapper()->mapAssoc([
            'datum_schliessungs_beginn' => '1.9.25',
            'uhrzeit_schliessungs_beginn' => '9:5',
            'datum_schliessungs_ende' => '01.09.1999',
            'uhrzeit_schliessungs_ende' => '7:05:3',
            'eingetragen_am' => '23.9.25 11:50',
            'geaendert_am' => '3.1.70 0:0:0',
        ]);

        self::assertSame('01.09.2025', $dto->startsOn);
        self::assertSame('09:05:00', $dto->startsAtTime);
        self::assertSame('01.09.1999', $dto->endsOn);
        self::assertSame('07:05:03', $dto->endsAtTime);
        self::assertSame('23.09.2025 11:50:00', $dto->sourceRecordedAt);
        self::assertSame('03.01.1970 00:00:00', $dto->sourceChangedAt);
    }

    public function testImpossibleDatesStayUntouched(): void
    {
        $dto = new ClosureRowMapper()->mapAssoc([
            'datum_schliessungs_beginn' => '32.01.2026',
            'eingetragen_am' => '32.01.25 10:00',
        ]);

        self::assertSame('32.01.2026', $dto->startsOn);
        self::assertSame('32.01.25 10:00', $dto->sourceRecordedAt);
    }

    public function testSplitKeepsAFlatRowUnchanged(): void
    {
        $row = [
            'schliessungseinheit' => 'ITS',
            'fachgebiet' => 'Innere Medizin',
            'fachbereich' => 'Kardiologie',
            'behandlungsdringlichkeit' => 'Notfallversorgung',
            'bemerkung' => "Zeile 1\nZeile 2",
        ];

        self::assertSame([$row], new ClosureRowMapper()->split($row));
    }

    public function testSplitTurnsAGroupedRecordIntoOneRowPerLine(): void
    {
        $rows = new ClosureRowMapper()->split([
            'schliessungseinheit' => 'ITS abmelden',
            'fachgebiet' => "Chirurgie\nChirurgie \nInnere Medizin",
            'fachbereich' => "Chir. Intensiv \nChir. Intensiv\nKardiologie",
            'behandlungsdringlichkeit' => "Notfallversorgung\nStationäre Versorgung\r\nNotfallversorgung",
            'datum_schliessungs_beginn' => '01.01.2026',
        ]);

        self::assertSame([
            ['Chirurgie', 'Chir. Intensiv', 'Notfallversorgung'],
            ['Chirurgie', 'Chir. Intensiv', 'Stationäre Versorgung'],
            ['Innere Medizin', 'Kardiologie', 'Notfallversorgung'],
        ], array_map(
            static fn (array $row): array => [$row['fachgebiet'], $row['fachbereich'], $row['behandlungsdringlichkeit']],
            $rows,
        ));
        foreach ($rows as $row) {
            self::assertSame('ITS abmelden', $row['schliessungseinheit']);
            self::assertSame('01.01.2026', $row['datum_schliessungs_beginn']);
        }
    }

    public function testSplitRejectsListsOfDifferentLength(): void
    {
        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('Grouped closure lists differ in length: speciality 2, department 2, care level 1');

        new ClosureRowMapper()->split([
            'fachgebiet' => "Chirurgie\nInnere Medizin",
            'fachbereich' => "Chir. Intensiv\nKardiologie",
            'behandlungsdringlichkeit' => 'Notfallversorgung',
        ]);
    }
}
