<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Mapping;

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
}
