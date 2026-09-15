<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Factory\AllocationImporterFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRowReader;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class AllocationImportNewIndicationRawTest extends DatabaseKernelTestCase
{
    public function testCatalogMissCreatesIndicationRawWithoutNullingImportName(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = UserFactory::createOne(['username' => 'catalog-miss-import-user']);
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
        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Noro']);

        $userRef = $em->getReference(\App\User\Domain\Entity\User::class, $user->getId());
        $hospitalRef = $em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Sekundäranlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $rows = [
            [
                'Leitstelle Test', '1', $hospital->getName(), 'KH Test',
                '07.01.2025', '10:19', '07.01.2025', '13:14',
                'W', '74', 'S+', 'H+', 'R+', 'B-',
                '', 'N-', 'Boden', '07.01.2025', '10:19',
                '559741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', '', 'Patient', 'Noro',
                '559 Hüft-/Schenkelhalsfraktur',
            ],
        ];

        $import = new Import()
            ->setName('Juni 2026')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setStatus(ImportStatus::PENDING)
            ->setType(ImportType::ALLOCATION)
            ->setFilePath('in-memory.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(123)
            ->setRowCount(1)
            ->setRunCount(0)
            ->setRunTime(0);

        $em->persist($import);
        $em->flush();
        $importId = (int) $import->getId();

        $importer = self::getContainer()->get(AllocationImporterFactory::class)
            ->create(new InMemoryRowReader($header, $rows), new InMemoryRejectWriter());

        $summary = $importer->import($import);

        self::assertSame(1, $summary->total);
        self::assertSame(1, $summary->ok);
        self::assertSame(0, $summary->rejected);

        $em->clear();

        $reloaded = $em->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $reloaded);
        self::assertSame('Juni 2026', $reloaded->getName());

        $raw = $em->getRepository(IndicationRaw::class)->findOneBy([
            'code' => 559,
            'name' => 'Hüft-/Schenkelhalsfraktur',
        ]);
        self::assertInstanceOf(IndicationRaw::class, $raw);
        self::assertSame($user->getId(), $raw->getCreatedBy()?->getId());

        $allocation = $em->createQueryBuilder()
            ->select('a')
            ->from(Allocation::class, 'a')
            ->andWhere('a.import = :imp')
            ->setParameter('imp', $importId)
            ->getQuery()
            ->getOneOrNullResult();

        self::assertInstanceOf(Allocation::class, $allocation);
        self::assertSame($raw->getId(), $allocation->getIndicationRaw()?->getId());
    }
}
