<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Mapping;

use App\Import\Infrastructure\Mapping\AllocationRowMapper;
use PHPUnit\Framework\TestCase;

final class AllocationRowMapperDispatchAreaSourceTest extends TestCase
{
    private AllocationRowMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AllocationRowMapper();
    }

    public function testZuweisungDurchOverridesVersorgungsbereich(): void
    {
        $dto = $this->mapper->mapAssoc([
            'krankenhaus_kurzname' => 'KH Test',
            'zuweisung_durch' => 'Leitstelle Schwalm-Eder (Disponent)',
            'versorgungsbereich' => 'Leitstelle Waldeck-Frankenberg',
            'datum_erstellungsdatum' => '07.01.2025',
            'uhrzeit_erstellungsdatum' => '10:19',
            'datum_eintreffzeit' => '07.01.2025',
            'uhrzeit_eintreffzeit' => '13:14',
            'geschlecht' => 'M',
            'pzc' => '123001',
        ]);

        self::assertSame('Schwalm-Eder', $dto->dispatchArea);
    }

    public function testKoordinierungsstelleUsesVersorgungsbereich(): void
    {
        $dto = $this->mapper->mapAssoc([
            'krankenhaus_kurzname' => 'KH Test',
            'zuweisung_durch' => 'Koordinierungsstelle für Sekundärtransporte - HE (Einsatzbearbeiter KST Hessen)',
            'versorgungsbereich' => 'Leitstelle Frankfurt',
            'datum_erstellungsdatum' => '07.01.2025',
            'uhrzeit_erstellungsdatum' => '10:19',
            'datum_eintreffzeit' => '07.01.2025',
            'uhrzeit_eintreffzeit' => '13:14',
            'geschlecht' => 'M',
            'pzc' => '123001',
        ]);

        self::assertSame('Frankfurt', $dto->dispatchArea);
    }

    public function testBerlinAccountNormalizesToDispatchAreaName(): void
    {
        $dto = $this->mapper->mapAssoc([
            'krankenhaus_kurzname' => 'KH Test',
            'zuweisung_durch' => 'Berlin (Disponent)',
            'versorgungsbereich' => 'Leitstelle Waldeck-Frankenberg',
            'datum_erstellungsdatum' => '07.01.2025',
            'uhrzeit_erstellungsdatum' => '10:19',
            'datum_eintreffzeit' => '07.01.2025',
            'uhrzeit_eintreffzeit' => '13:14',
            'geschlecht' => 'M',
            'pzc' => '123001',
        ]);

        self::assertSame('Berlin', $dto->dispatchArea);
    }
}
