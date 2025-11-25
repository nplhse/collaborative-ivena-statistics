<?php

// tests/Integration/Service/Import/AllocationImportFeatureTest.php
declare(strict_types=1);

namespace App\Tests\Integration\Service\Import;

use App\Entity\Allocation;
use App\Entity\Import;
use App\Enum\ImportStatus;
use App\Enum\ImportType;
use App\Factory\AssignmentFactory;
use App\Factory\DepartmentFactory;
use App\Factory\DispatchAreaFactory;
use App\Factory\HospitalFactory;
use App\Factory\IndicationRawFactory;
use App\Factory\InfectionFactory;
use App\Factory\OccasionFactory;
use App\Factory\SpecialityFactory;
use App\Factory\StateFactory;
use App\Service\Import\Adapter\DoctrineAllocationPersister;
use App\Service\Import\AllocationImporter;
use App\Service\Import\Mapping\AllocationImportFactory;
use App\Service\Import\Mapping\AllocationRowMapper;
use App\Tests\Doubles\Service\Import\Adapter\InMemoryRejectWriter;
use App\Tests\Doubles\Service\Import\Adapter\InMemoryRowReader;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AllocationImportFeatureTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFullImportPipelinePersistsEntitiesAndWritesRejects(): void
    {
        // Arrange
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Testkrankenhaus Musterstadt',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        AssignmentFactory::createOne(['name' => 'Patient']);
        AssignmentFactory::createOne(['name' => 'RD']);

        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $user->getId());
        $hospitalRef = $this->em->getReference(\App\Entity\Hospital::class, $hospital->getId());

        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $rows = [
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication'],
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '02.03.2025', '15:09', '02.03.2025', '16:43', 'D', '34', 'S-', '', '', 'B-', '', 'N-', 'Boden', '02.03.2025', '15:09', '123341', 'Innere Medizin', 'Kardiologie', 'Ja', 'Öffentlicher Raum', 'RD', 'Keine', '123 Test Indication'],
            ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '16.02.2025', '12:00', '16.02.2025', '13:01', 'W', '0', '', '', '', 'B-', '', 'N-', 'Boden', '16.02.2025', '12:00', '123001', 'Innere Medizin', 'Kardiologie', 'Ja', 'Häuslicher Einsatz', 'Patient', 'V.a. COVID', '123 Test Indication'],
        ];

        $reader = new InMemoryRowReader($header, $rows);
        $rejectWriter = new InMemoryRejectWriter();

        $import = new Import()
            ->setName('Integration Import')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setStatus(ImportStatus::PENDING)
            ->setType(ImportType::ALLOCATION)
            ->setFilePath('in-memory.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(123)
            ->setRowCount(3)
            ->setRunCount(0)
            ->setRunTime(0);

        $this->em->persist($import);
        $this->em->flush();

        // Services
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $mapper = static::getContainer()->get(AllocationRowMapper::class);
        $factory = static::getContainer()->get(AllocationImportFactory::class);
        $persister = static::getContainer()->get(DoctrineAllocationPersister::class);

        $importer = new AllocationImporter(
            validator: $validator,
            reader: $reader,
            mapper: $mapper,
            factory: $factory,
            persister: $persister,
            rejectWriter: $rejectWriter,
            logger: new NullLogger()
        );

        // Act
        $result = $importer->import($import);

        // Assert
        self::assertSame(['total' => 3, 'ok' => 2, 'rejected' => 1], $result);
        self::assertSame(1, $rejectWriter->getCount());

        $countOk = $this->countAllocationsForImportId((int) $import->getId());
        self::assertSame(2, $countOk, 'Expected 2 persisted allocations');

        $one = $this->findOneAllocationForImportId((int) $import->getId());
        self::assertInstanceOf(Allocation::class, $one);
        self::assertContains($one->getGender()->value, ['M', 'F', 'X']);
        self::assertNotNull($one->getCreatedAt());
        self::assertNotNull($one->getArrivalAt());
    }

    private function countAllocationsForImportId(int $importId): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Allocation::class, 'a')
            ->andWhere('a.import = :imp')
            ->setParameter('imp', $importId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function findOneAllocationForImportId(int $importId): ?Allocation
    {
        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->andWhere('a.import = :imp')
            ->setParameter('imp', $importId)
            ->setMaxResults(1);

        /* @var ?Allocation $one */
        return $qb->getQuery()->getOneOrNullResult();
    }
}
