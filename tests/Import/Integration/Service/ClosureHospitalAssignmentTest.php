<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Message\ImportClosuresMessage;
use App\Import\Application\MessageHandler\ImportClosuresMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportReject;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ClosureHospitalAssignmentTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;

    private ImportClosuresMessageHandler $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->handler = self::getContainer()->get(ImportClosuresMessageHandler::class);
        UserFactory::createOne();
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
    }

    public function testDifferentShortNameUsesTheSelectedHospital(): void
    {
        $owner = $this->betaOwner();
        $marburg = $this->hospitalOwnedBy($owner, 'Universitätsklinikum Gießen und Marburg, Standort Marburg');
        $kassel = $this->hospitalOwnedBy($this->betaOwner(), 'Klinikum Kassel');
        $id = $this->storeImport($owner, $marburg, [
            $this->row('Universitätsklinikum Marburg'),
        ]);

        $this->handler->__invoke(new ImportClosuresMessage($id));

        $this->em->clear();
        $import = $this->em->find(Import::class, $id);
        self::assertInstanceOf(Import::class, $import);
        self::assertSame(ImportStatus::COMPLETED, $import->getStatus());
        self::assertSame(1, $import->getRowsPassed());
        self::assertSame(0, $import->getRowsRejected());

        $intervals = $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $id]);
        self::assertCount(1, $intervals);
        self::assertSame($marburg->getId(), $intervals[0]->getHospital()?->getId());
        self::assertSame([], $this->em->getRepository(ClosureInterval::class)->findBy(['hospital' => $kassel->getId()]));
        self::assertSame(
            'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            $this->em->find(Hospital::class, $marburg->getId())?->getName(),
        );
    }

    public function testShortNameOfAnotherHospitalRejectsWithoutChangingTheCatalog(): void
    {
        $owner = $this->betaOwner();
        $marburg = $this->hospitalOwnedBy($owner, 'Universitätsklinikum Gießen und Marburg, Standort Marburg');
        $this->hospitalOwnedBy($this->betaOwner(), 'Klinikum Kassel');
        $id = $this->storeImport($owner, $marburg, [
            $this->row('Klinikum Kassel'),
        ]);

        $this->handler->__invoke(new ImportClosuresMessage($id));

        $this->em->clear();
        $import = $this->em->find(Import::class, $id);
        self::assertInstanceOf(Import::class, $import);
        self::assertSame(ImportStatus::FAILED, $import->getStatus());
        self::assertSame(1, $import->getRowCount());
        self::assertSame(0, $import->getRowsPassed());
        self::assertSame(1, $import->getRowsRejected());
        self::assertSame([], $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $id]));
        self::assertSame(
            'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            $this->em->find(Hospital::class, $marburg->getId())?->getName(),
        );

        $rejects = $this->em->getRepository(ImportReject::class)->findBy(['import' => $id]);
        self::assertNotSame([], $rejects);
        self::assertStringContainsString('HOSPITAL_CONFLICT', implode("\n", $rejects[0]->getMessages()));
    }

    public function testSeveralShortNamesAreNotAssignedWhenNoneMatchTheSelectedName(): void
    {
        $owner = $this->betaOwner();
        $marburg = $this->hospitalOwnedBy($owner, 'Universitätsklinikum Gießen und Marburg, Standort Marburg');
        $this->hospitalOwnedBy($this->betaOwner(), 'Klinikum Kassel');
        $id = $this->storeImport($owner, $marburg, [
            $this->row('Universitätsklinikum Marburg'),
            $this->row('Klinikum Kassel'),
        ]);

        $this->handler->__invoke(new ImportClosuresMessage($id));

        $this->em->clear();
        $import = $this->em->find(Import::class, $id);
        self::assertInstanceOf(Import::class, $import);
        self::assertSame(ImportStatus::FAILED, $import->getStatus());
        self::assertSame(2, $import->getRowCount());
        self::assertSame(0, $import->getRowsPassed());
        self::assertSame(2, $import->getRowsRejected());
        self::assertSame([], $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $id]));
        self::assertSame(
            'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            $this->em->find(Hospital::class, $marburg->getId())?->getName(),
        );
    }

    private function betaOwner(): User
    {
        return UserFactory::createOne([
            'username' => 'closure-hospital-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT', 'ROLE_CLOSURE_BETA'],
        ]);
    }

    private function hospitalOwnedBy(User $owner, string $name): Hospital
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

    /**
     * @param list<string> $rows
     */
    private function storeImport(User $owner, Hospital $hospital, array $rows): int
    {
        $csv = "Krankenhaus-Kurzname;Fachgebiet;Fachbereich;Behandlungsdringlichkeit;Datum (Schließungs-Beginn);Uhrzeit (Schließungs-Beginn);Schließungs-Dauer (Minuten);Datum (Schließungs-Ende);Uhrzeit (Schließungs-Ende);Grund;Eingetragen am;Geändert am;Typ\n";
        $csv .= implode("\n", $rows)."\n";

        $importsBaseDir = (string) self::getContainer()->getParameter('app.imports_base_dir');
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $targetDir = $importsBaseDir.'/_tests/closure-hospital/'.bin2hex(random_bytes(4));
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

        $import = new Import()
            ->setName('Closure hospital assignment')
            ->setHospital($this->em->getReference(Hospital::class, $hospital->getId()))
            ->setCreatedBy($this->em->getReference(User::class, $owner->getId()))
            ->setType(ImportType::CLOSURE)
            ->setStatus(ImportStatus::PENDING)
            ->setFilePath($storedPath)
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(strlen($csv))
            ->setRunCount(0)
            ->setRunTime(0)
            ->setRowCount(0);
        $this->em->persist($import);
        $this->em->flush();

        return (int) $import->getId();
    }

    private function row(string $shortName): string
    {
        return $shortName.';Innere Medizin;Kardiologie;Notfallversorgung;01.01.2026;00:10:00;60;01.01.2026;01:10:00;Überlastung der Notaufnahme;01.01.2026 00:20:22;01.01.2026 00:20:22;Klinik';
    }
}
