<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\MessageHandler\ImportClosuresMessageHandler;
use App\Import\Application\Service\DevelopmentHospitalOverride;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Development-only target hospital override for closure imports (#878).
 *
 * The file names "Klinikum Weststadt", a different catalog hospital. Row 1 is valid, row 2 names an
 * unknown department. With the override in `dev`, row 1 is stored on the selected demo hospital and
 * row 2 is still rejected; outside `dev` both rows are rejected as HOSPITAL_CONFLICT.
 */
final class ImportClosuresHospitalOverrideTest extends DatabaseKernelTestCase
{
    private const string FILE_HOSPITAL = 'Klinikum Weststadt';

    public function testOverrideStoresRowsOnTheSelectedHospitalInDevelopment(): void
    {
        self::bootKernel();
        self::getContainer()->set(DevelopmentHospitalOverride::class, new DevelopmentHospitalOverride('dev'));
        ['id' => $id, 'hospital' => $hospitalId] = $this->arrangeImport(hospitalOverride: true);

        $this->runImport($id);

        $import = $this->freshImport($id);
        self::assertSame(2, $import->getRowCount());
        self::assertSame(1, $import->getRowsPassed());
        self::assertSame(1, $import->getRowsRejected(), 'The unknown department is still rejected.');
        self::assertTrue($import->isHospitalOverride());
        self::assertSame(self::FILE_HOSPITAL, $import->getHospitalOverrideSourceLabel());

        $intervals = $this->em()->getRepository(ClosureInterval::class)->findBy(['import' => $id]);
        self::assertCount(1, $intervals);
        self::assertSame($hospitalId, $intervals[0]->getHospital()?->getId());
        self::assertSame(['REF_NOT_FOUND' => 1], $this->rejectCodes($id));
    }

    public function testStoredOverrideIsIgnoredOutsideDevelopment(): void
    {
        self::bootKernel();
        self::assertFalse(self::getContainer()->get(DevelopmentHospitalOverride::class)->isAvailable(), 'The test environment is not dev.');
        ['id' => $id] = $this->arrangeImport(hospitalOverride: true);

        $this->runImport($id);

        $import = $this->freshImport($id);
        self::assertSame(0, $import->getRowsPassed());
        self::assertSame(2, $import->getRowsRejected());
        self::assertNull($import->getHospitalOverrideSourceLabel());
        self::assertSame([], $this->em()->getRepository(ClosureInterval::class)->findBy(['import' => $id]));
        self::assertSame(['HOSPITAL_CONFLICT' => 2], $this->rejectCodes($id));
    }

    public function testImportWithoutOverrideKeepsTheConflictCheckInDevelopment(): void
    {
        self::bootKernel();
        self::getContainer()->set(DevelopmentHospitalOverride::class, new DevelopmentHospitalOverride('dev'));
        ['id' => $id] = $this->arrangeImport(hospitalOverride: false);

        $this->runImport($id);

        $import = $this->freshImport($id);
        self::assertSame(0, $import->getRowsPassed());
        self::assertSame(['HOSPITAL_CONFLICT' => 2], $this->rejectCodes($id));
    }

    private function runImport(int $id): void
    {
        $run = self::getContainer()->get(ImportRunLifecycle::class)->register($id, ImportRunTrigger::Console);
        self::getContainer()->get(ImportClosuresMessageHandler::class)->__invoke(new ImportClosuresMessage($id, $run->getId()->toRfc4122()));
    }

    private function freshImport(int $id): Import
    {
        $this->em()->clear();
        $import = self::getContainer()->get(ImportRepository::class)->find($id);
        self::assertInstanceOf(Import::class, $import);

        return $import;
    }

    /**
     * @return array<string, int> reject code => rows
     */
    private function rejectCodes(int $importId): array
    {
        /** @var list<string> $messages */
        $messages = self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT messages::text FROM import_reject WHERE import_id = ?',
            [$importId],
        );

        $codes = [];
        foreach ($messages as $json) {
            foreach (['HOSPITAL_CONFLICT', 'HOSPITAL_MISMATCH', 'REF_NOT_FOUND'] as $code) {
                if (str_contains($json, $code)) {
                    $codes[$code] = ($codes[$code] ?? 0) + 1;
                }
            }
        }

        return $codes;
    }

    /**
     * @return array{id: int, hospital: int}
     */
    private function arrangeImport(bool $hospitalOverride): array
    {
        $owner = UserFactory::createOne([
            'username' => 'closure-override-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA'],
        ]);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $target = HospitalFactory::createOne(['name' => 'Demo-Klinikum Talstadt', 'owner' => $owner, 'state' => $state, 'dispatchArea' => $dispatch]);
        HospitalFactory::createOne(['name' => self::FILE_HOSPITAL, 'state' => $state, 'dispatchArea' => $dispatch]);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        $csv = "Krankenhaus-Kurzname;Fachgebiet;Fachbereich;Behandlungsdringlichkeit;Datum (Schließungs-Beginn);Uhrzeit (Schließungs-Beginn);Schließungs-Dauer (Minuten);Datum (Schließungs-Ende);Uhrzeit (Schließungs-Ende);Grund;Eingetragen am;Geändert am;Typ\n";
        $csv .= self::FILE_HOSPITAL.";Innere Medizin;Kardiologie;Notfallversorgung;01.01.2026;00:10:00;60;01.01.2026;01:10:00;Überlastung der Notaufnahme;01.01.2026 00:20:22;01.01.2026 00:20:22;Klinik\n";
        $csv .= self::FILE_HOSPITAL.";Innere Medizin;Unbekannter Fachbereich;Notfallversorgung;02.01.2026;08:00:00;30;02.01.2026;08:30:00;Überlastung der Notaufnahme;02.01.2026 08:05:00;02.01.2026 08:05:00;Klinik\n";

        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $targetDir = $importsBaseDir.'/_tests/closure-override/'.bin2hex(random_bytes(4));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            self::fail('Unable to create imports test directory: '.$targetDir);
        }
        $absolutePath = $targetDir.'/closure.csv';
        self::assertNotFalse(file_put_contents($absolutePath, $csv));
        $storedPath = ltrim(str_replace('\\', '/', (string) preg_replace('#^'.preg_quote($projectDir, '#').'/?#', '', $absolutePath)), '/');

        $em = $this->em();
        $import = new Import()
            ->setName('Closure override')
            ->setHospital($em->getReference(Hospital::class, $target->getId()))
            ->setCreatedBy($em->getReference(User::class, $owner->getId()))
            ->setType(ImportType::CLOSURE)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($storedPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(10)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0)
            ->setHospitalOverride($hospitalOverride);
        $em->persist($import);
        $em->flush();

        return ['id' => (int) $import->getId(), 'hospital' => (int) $target->getId()];
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
