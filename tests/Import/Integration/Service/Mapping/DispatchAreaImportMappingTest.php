<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service\Mapping;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Mapping\AllocationImportFactory;
use App\Import\Infrastructure\Mapping\AllocationRowMapper;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class DispatchAreaImportMappingTest extends KernelTestCase
{
    use ResetDatabase;

    private AllocationImportFactory $factory;
    private AllocationRowMapper $mapper;
    private Import $import;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        UserFactory::createOne();

        $state = StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Schwalm-Eder', 'state' => $state]);
        DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);

        $hospital = HospitalFactory::createOne([
            'name' => 'Test Hospital',
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::find(['name' => 'Schwalm-Eder']),
        ]);

        AssignmentFactory::createOne(['name' => 'Patient']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123]);

        $this->import = $em->getRepository(Import::class)->find(
            ImportFactory::createOne(['name' => 'Dispatch Area Import', 'hospital' => $hospital])->getId()
        );

        $this->factory = self::getContainer()->get(AllocationImportFactory::class);
        $this->factory->warm();
        $this->mapper = self::getContainer()->get(AllocationRowMapper::class);
    }

    public function testSchwalmEderDisponentResolvesToDispatchAreaEntity(): void
    {
        $dto = $this->mapper->mapAssoc($this->baseRow([
            'zuweisung_durch' => 'Leitstelle Schwalm-Eder (Disponent)',
            'versorgungsbereich' => 'Leitstelle Waldeck-Frankenberg',
        ]));

        $allocation = $this->factory->fromDto($dto, $this->import);

        self::assertSame('Schwalm-Eder', $allocation->getDispatchArea()->getName());
    }

    public function testKoordinierungsstelleUsesVersorgungsbereichForLookup(): void
    {
        $dto = $this->mapper->mapAssoc($this->baseRow([
            'zuweisung_durch' => 'Koordinierungsstelle für Sekundärtransporte - HE (Einsatzbearbeiter KST Hessen)',
            'versorgungsbereich' => 'Leitstelle Frankfurt',
        ]));

        $allocation = $this->factory->fromDto($dto, $this->import);

        self::assertSame('Frankfurt', $allocation->getDispatchArea()->getName());
    }

    public function testBerlinAccountFailsLookup(): void
    {
        $dto = $this->mapper->mapAssoc($this->baseRow([
            'zuweisung_durch' => 'Berlin (Disponent)',
            'versorgungsbereich' => 'Leitstelle Waldeck-Frankenberg',
        ]));

        $this->expectException(ReferenceNotFoundException::class);
        $this->factory->fromDto($dto, $this->import);
    }

    /**
     * @param array<string, string> $override
     *
     * @return array<string, string>
     */
    private function baseRow(array $override = []): array
    {
        return array_merge([
            'krankenhaus_kurzname' => 'KH Test',
            'datum_erstellungsdatum' => '07.01.2025',
            'uhrzeit_erstellungsdatum' => '10:19',
            'datum_eintreffzeit' => '07.01.2025',
            'uhrzeit_eintreffzeit' => '13:14',
            'geschlecht' => 'M',
            'alter' => '74',
            'schockraum' => 'false',
            'herzkatheter' => 'false',
            'reanimation' => 'false',
            'beatmet' => 'false',
            'schock' => 'false',
            'schwanger' => 'false',
            'arbeits_wege_schulunfall' => 'false',
            'arztbegleitet' => 'false',
            'transportmittel' => 'Boden',
            'pzc' => '123741',
            'fachgebiet' => 'Innere Medizin',
            'fachbereich' => 'Kardiologie',
            'fachbereich_war_abgemeldet' => 'false',
            'grund' => 'Patient',
            'anlass' => 'Sonstiger Einsatz',
            'sekundaeranlass' => 'Kapazitätsengpass',
            'ansteckungsfaehig' => 'Keine',
            'pzc_und_text' => '123 Test Indication',
        ], $override);
    }
}
