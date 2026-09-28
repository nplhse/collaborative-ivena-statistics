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
use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Event\ImportFailed;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\MessageHandler\ImportClosuresMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ImportClosuresMessageHandlerTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;
    private ImportRepository $imports;
    private ImportClosuresMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->imports = $container->get(ImportRepository::class);
        $this->handler = $container->get(ImportClosuresMessageHandler::class);
    }

    public function testUnknownImportIdDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->handler->__invoke(new ImportClosuresMessage(2_147_483_647));
    }

    public function testSuccessfulRunStoresIntervalsAndASecondRunCleansThePreviousOnes(): void
    {
        ['id' => $id] = $this->arrangeRunnableImport();
        $completed = [];
        $failed = [];
        $this->listen($completed, $failed);

        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame([$id], $completed);
        self::assertSame([], $failed);
        $fresh = $this->imports->find($id);
        self::assertInstanceOf(Import::class, $fresh);
        self::assertSame(ImportStatus::COMPLETED, $fresh->getStatus());
        self::assertSame(1, $fresh->getRowCount());
        self::assertSame(1, $fresh->getRowsPassed());
        self::assertCount(1, $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $id]));

        $completedAgain = [];
        $failedAgain = [];
        $this->listen($completedAgain, $failedAgain);
        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame([$id], $completedAgain);
        self::assertSame([], $failedAgain);
        self::assertCount(1, $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $id]));
    }

    public function testMissingCsvMarksTheImportFailed(): void
    {
        $owner = $this->betaOwner();
        $hospital = $this->hospitalOwnedBy($owner);
        $id = $this->persistImport(
            $owner,
            $hospital,
            ImportType::CLOSURE,
            'var/imports/'.date('Y/m').'/closure-missing-'.bin2hex(random_bytes(6)).'.csv',
        );
        $completed = [];
        $failed = [];
        $this->listen($completed, $failed);

        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame([$id], $failed);
        self::assertSame([], $completed);
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testPathOutsideTheImportDirectoryMarksTheImportFailed(): void
    {
        $owner = $this->betaOwner();
        $hospital = $this->hospitalOwnedBy($owner);
        $id = $this->persistImport($owner, $hospital, ImportType::CLOSURE, '/etc/passwd');
        $completed = [];
        $failed = [];
        $this->listen($completed, $failed);

        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame([$id], $failed);
        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testWrongImportTypeMarksTheImportFailed(): void
    {
        $owner = $this->betaOwner();
        $hospital = $this->hospitalOwnedBy($owner);
        $id = $this->persistImport($owner, $hospital, ImportType::ALLOCATION, 'var/imports/unused.csv');

        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testCreatorWithoutBetaRoleMarksTheImportFailed(): void
    {
        $owner = UserFactory::createOne(['username' => 'closure-no-beta-'.bin2hex(random_bytes(3)), 'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $hospital = $this->hospitalOwnedBy($owner);
        $id = $this->persistImport($owner, $hospital, ImportType::CLOSURE, 'var/imports/unused.csv');

        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testCreatorWithoutImportPermissionMarksTheImportFailed(): void
    {
        $owner = $this->betaOwner();
        $stranger = $this->betaOwner();
        $hospital = $this->hospitalOwnedBy($owner);
        $id = $this->persistImport($stranger, $hospital, ImportType::CLOSURE, 'var/imports/unused.csv');

        $this->handler->__invoke(new ImportClosuresMessage($id));

        self::assertSame(ImportStatus::FAILED, $this->imports->find($id)?->getStatus());
    }

    public function testRunFailureMarksTheImportFailedAndRethrows(): void
    {
        $owner = $this->betaOwner();
        $hospital = $this->hospitalOwnedBy($owner);
        $import = $this->em->find(Import::class, $this->persistImport($owner, $hospital, ImportType::CLOSURE, 'var/imports/unused.csv'));
        self::assertInstanceOf(Import::class, $import);

        $reader = new class implements \App\Import\Application\Contracts\RowReaderInterface {
            public function rows(): iterable
            {
                return [];
            }

            public function header(): ?array
            {
                return null;
            }

            public function rowsAssoc(): iterable
            {
                throw new \RuntimeException('closure row reader failed');
            }
        };
        $writer = $this->createStub(\App\Import\Application\Contracts\RejectWriterInterface::class);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('closure row reader failed');

        try {
            $this->handler->run($import, $reader, $writer);
        } finally {
            self::assertSame(ImportStatus::FAILED, $this->imports->find($import->getId())?->getStatus());
        }
    }

    public function testPermissionReasonsForMissingCreatorAndHospital(): void
    {
        $method = new \ReflectionMethod(ImportClosuresMessageHandler::class, 'resolvePermissionFailureReason');

        $withoutCreator = new Import()->setType(ImportType::CLOSURE);
        self::assertSame('Import has no creator user', $method->invoke($this->handler, $withoutCreator));

        $creator = $this->betaOwner();
        $withoutHospital = new Import()
            ->setType(ImportType::CLOSURE)
            ->setCreatedBy($this->em->getReference(User::class, $creator->getId()));
        self::assertSame('Import has no hospital', $method->invoke($this->handler, $withoutHospital));
    }

    /**
     * @param list<int> $completed
     * @param list<int> $failed
     */
    private function listen(array &$completed, array &$failed): void
    {
        $dispatcher = self::getContainer()->get(EventDispatcherInterface::class);
        $dispatcher->addListener(ImportCompleted::class, function (object $event) use (&$completed): void {
            if ($event instanceof ImportCompleted) {
                $completed[] = $event->importId;
            }
        });
        $dispatcher->addListener(ImportFailed::class, function (object $event) use (&$failed): void {
            if ($event instanceof ImportFailed) {
                $failed[] = $event->importId;
            }
        });
    }

    /**
     * @return array{id: int}
     */
    private function arrangeRunnableImport(): array
    {
        $owner = $this->betaOwner();
        $hospital = $this->hospitalOwnedBy($owner, 'Closure Handler Hospital');
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);

        $csv = "Krankenhaus-Kurzname;Fachgebiet;Fachbereich;Behandlungsdringlichkeit;Datum (Schließungs-Beginn);Uhrzeit (Schließungs-Beginn);Schließungs-Dauer (Minuten);Datum (Schließungs-Ende);Uhrzeit (Schließungs-Ende);Grund;Eingetragen am;Geändert am;Typ\n";
        $csv .= "Closure Handler Hospital;Innere Medizin;Kardiologie;Notfallversorgung;01.01.2026;00:10:00;60;01.01.2026;01:10:00;Überlastung der Notaufnahme;01.01.2026 00:20:22;01.01.2026 00:20:22;Klinik\n";

        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $targetDir = $importsBaseDir.'/_tests/closure-handler/'.bin2hex(random_bytes(4));
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            self::fail('Unable to create imports test directory: '.$targetDir);
        }
        $absolutePath = $targetDir.'/closure.csv';
        self::assertNotFalse(file_put_contents($absolutePath, $csv));
        $storedPath = ltrim(str_replace('\\', '/', (string) preg_replace(
            '#^'.preg_quote($projectDir, '#').'/?#',
            '',
            $absolutePath,
        )), '/');

        return ['id' => $this->persistImport($owner, $hospital, ImportType::CLOSURE, $storedPath)];
    }

    private function betaOwner(): User
    {
        return UserFactory::createOne([
            'username' => 'closure-beta-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA'],
        ]);
    }

    private function hospitalOwnedBy(User $owner, string $name = 'Closure Permission Hospital'): Hospital
    {
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);

        return HospitalFactory::createOne([
            'name' => $name,
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
    }

    private function persistImport(User $creator, Hospital $hospital, ImportType $type, string $filePath): int
    {
        $import = new Import()
            ->setName('Closure handler')
            ->setHospital($this->em->getReference(Hospital::class, $hospital->getId()))
            ->setCreatedBy($this->em->getReference(User::class, $creator->getId()))
            ->setType($type)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($filePath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(10)
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);
        $this->em->persist($import);
        $this->em->flush();

        return (int) $import->getId();
    }
}
