<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Assessment;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\MessageHandler\ImportAllocationsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

abstract class ImportAllocationsMessageHandlerTestCase extends DatabaseKernelTestCase
{
    protected EntityManagerInterface $em;
    protected ImportRepository $imports;
    protected ImportAllocationsMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = self::getContainer();

        $this->em = $c->get(EntityManagerInterface::class);
        $this->imports = $c->get(ImportRepository::class);
        $this->handler = $c->get(ImportAllocationsMessageHandler::class);
    }

    protected function createPersistedImport(
        ImportStatus $status,
        int $rowCount = 0,
        int $rowsPassed = 0,
        int $rowsRejected = 0,
    ): Import {
        $owner = UserFactory::createOne(['username' => 'import-status']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['name' => 'StatusEvt'.bin2hex(random_bytes(4)), 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Status Hospital '.bin2hex(random_bytes(4)),
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Dispatch status IT')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus($status)
            ->setFilePath('/tmp/unused.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(1)
            ->setRunTime(10)
            ->setRowCount($rowCount)
            ->setRowsPassed($rowsPassed)
            ->setRowsRejected($rowsRejected);

        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    /**
     * @return array{id: int, csvPath: string}
     */
    protected function arrangeAllocationImportSampleCsvImport(): array
    {
        $owner = UserFactory::createOne(['username' => 'import-sample-'.bin2hex(random_bytes(4))]);
        $createdBy = UserFactory::createOne(['username' => 'import-sample-creator-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Test Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Patient']);
        AssignmentFactory::createOne(['name' => 'RD']);
        AssignmentFactory::createOne(['name' => 'ZLST']);
        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);
        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);
        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);
        IndicationRawFactory::createOne(['name' => 'Test Indication', 'code' => 123, 'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4']);

        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $fixturePath = $projectDir.'/tests/Import/Fixtures/allocation_import_sample.csv';
        self::assertFileExists($fixturePath);

        $targetDir = $projectDir.'/var/tests/imports/'.date('Y/m');
        @mkdir($targetDir, 0775, true);
        $csvPath = $targetDir.'/allocation_import_sample_'.bin2hex(random_bytes(4)).'.csv';
        copy($fixturePath, $csvPath);
        $relativePath = ltrim(str_replace('\\', '/', (string) preg_replace('#^'.preg_quote($projectDir, '#').'/?#', '', $csvPath)), '/');

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Allocation import sample fixture')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($relativePath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($csvPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return ['id' => (int) $import->getId(), 'csvPath' => $csvPath];
    }

    /**
     * @return array{id: int, csvPath: string}
     */
    protected function arrangeSingleRowSuccessfulCsvImport(bool $withAssessment = false): array
    {
        if ($withAssessment) {
            return $this->arrangeAssessmentCsvImport();
        }

        $suffix = bin2hex(random_bytes(5));

        $owner = UserFactory::createOne(['username' => 'import-csv-'.$suffix]);
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
        AssignmentFactory::createOne(['name' => 'ZLST']);

        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);

        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        IndicationRawFactory::createOne([
            'name' => 'Test Indication',
            'code' => 123,
            'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4',
        ]);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
        ];

        $row = ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', '123741', 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', '123 Test Indication'];

        $rows = [$row];

        $csvPath = sys_get_temp_dir().'/ivena-import-evt-'.bin2hex(random_bytes(8)).'.csv';
        $fh = fopen($csvPath, 'wb');
        self::assertNotFalse($fh);
        $delimiter = ';';
        $enclosure = '"';
        $escape = '\\';
        fputcsv($fh, $header, $delimiter, $enclosure, $escape);
        foreach ($rows as $csvRow) {
            fputcsv($fh, $csvRow, $delimiter, $enclosure, $escape);
        }
        fclose($fh);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler invoke IT '.$suffix)
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($csvPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($csvPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return ['id' => (int) $import->getId(), 'csvPath' => $csvPath];
    }

    /**
     * @return array{id: int, csvPath: string}
     */
    protected function arrangeAssessmentCsvImport(): array
    {
        $suffix = bin2hex(random_bytes(5));

        $owner = UserFactory::createOne(['username' => 'import-csv-'.$suffix]);
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
        AssignmentFactory::createOne(['name' => 'ZLST']);

        OccasionFactory::createOne(['name' => 'aus Arztpraxis']);
        OccasionFactory::createOne(['name' => 'Häuslicher Einsatz']);
        OccasionFactory::createOne(['name' => 'Öffentlicher Raum']);
        OccasionFactory::createOne(['name' => 'Sonstiger Einsatz']);

        SecondaryTransportFactory::createOne(['name' => 'Kapazitätsengpass']);

        InfectionFactory::createOne(['name' => 'Noro']);
        InfectionFactory::createOne(['name' => 'V.a. COVID']);

        IndicationRawFactory::createOne([
            'name' => 'Test Indication',
            'code' => 123,
            'hash' => '070f5e78cc3ce4b3c3378aeaa0a304a4',
        ]);

        $header = [
            'Versorgungsbereich', 'KHS-Versorgungsgebiet', 'Krankenhaus', 'Krankenhaus-Kurzname',
            'Datum', 'Uhrzeit', 'Datum (Eintreffzeit)', 'Uhrzeit (Eintreffzeit)',
            'Geschlecht', 'Alter', 'Schockraum', 'Herzkatheter', 'Reanimation', 'Beatmet',
            'Schwanger', 'Arztbegleitet', 'Transportmittel', 'Datum (Erstellungsdatum)', 'Uhrzeit (Erstellungsdatum)',
            'PZC', 'Fachgebiet', 'Fachbereich', 'Fachbereich war abgemeldet?', 'Anlass', 'Grund', 'Ansteckungsfähig',
            'PZC und Text',
            'Airway', 'Breathing', 'Circulation', 'Disability',
        ];

        $pzcCol = '123741';
        $pzTextCol = '123 Test Indication';

        $row = ['Leitstelle Test', '1', $hospital->getName(), 'KH Test', '07.01.2025', '10:19', '07.01.2025', '13:14', 'W', '74', 'S+', 'H+', 'R+', 'B-', '', 'N-', 'Boden', '07.01.2025', '10:19', $pzcCol, 'Innere Medizin', 'Kardiologie', 'Ja', 'aus Arztpraxis', 'Patient', 'Noro', $pzTextCol, 'A-Frei', 'B-Spontan', 'C-Stabil', 'D-Wach'];

        $rows = [$row];

        $csvPath = sys_get_temp_dir().'/ivena-import-evt-'.bin2hex(random_bytes(8)).'.csv';
        $fh = fopen($csvPath, 'wb');
        self::assertNotFalse($fh);
        $delimiter = ';';
        $enclosure = '"';
        $escape = '\\';
        fputcsv($fh, $header, $delimiter, $enclosure, $escape);
        foreach ($rows as $row) {
            fputcsv($fh, $row, $delimiter, $enclosure, $escape);
        }
        fclose($fh);

        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler invoke IT '.$suffix)
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($csvPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize((int) filesize($csvPath))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return ['id' => (int) $import->getId(), 'csvPath' => $csvPath];
    }

    protected function countAssessments(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Assessment::class, 'a')
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countAssessmentsForImport(int $importId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT ass.id)')
            ->from(Allocation::class, 'a')
            ->join('a.assessment', 'ass')
            ->where('a.import = :importId')
            ->setParameter('importId', $importId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function createInMemoryImport(
        \App\User\Domain\Entity\User $owner,
        \App\Allocation\Domain\Entity\Hospital $hospital,
    ): Import {
        $userRef = $this->em->getReference(\App\User\Domain\Entity\User::class, $owner->getId());
        $hospitalRef = $this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId());

        $import = new Import()
            ->setName('Handler IT (in-memory assessment)')
            ->setHospital($hospitalRef)
            ->setCreatedBy($userRef)
            ->setType(ImportType::ALLOCATION)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath('in-memory://allocations.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(0)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);

        $this->em->persist($import);
        $this->em->flush();

        return $import;
    }

    protected function countAllocationsForImport(int $importId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(Allocation::class, 'a')
            ->where('a.import = :importId')
            ->setParameter('importId', $importId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function countAuditEntriesForEntityClass(string $entityClass): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(AuditEntry::class, 'a')
            ->andWhere('a.entityClass = :class')
            ->setParameter('class', $entityClass)
            ->getQuery()
            ->getSingleScalarResult();
    }

    protected function importEntityHasAuditIntent(int $importId, string $intentName): bool
    {
        /** @var list<AuditEntry> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('a')
            ->from(AuditEntry::class, 'a')
            ->andWhere('a.entityClass = :class')
            ->setParameter('class', Import::class)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(80)
            ->getQuery()
            ->getResult();

        foreach ($rows as $row) {
            $meta = $row->getMetadata();
            if (!\is_array($meta)) {
                continue;
            }
            if (($meta['intent'] ?? null) !== $intentName) {
                continue;
            }
            $intentMeta = $meta['intent_metadata'] ?? [];
            if (!\is_array($intentMeta)) {
                continue;
            }
            $rowImportId = $intentMeta['import_id'] ?? null;
            if ((int) $rowImportId === $importId) {
                return true;
            }
        }

        return false;
    }
}
