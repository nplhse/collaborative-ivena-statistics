<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Domain\Entity\ClosureInterval;
use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Factory\ClosureImporterFactory;
use App\Import\Application\Factory\RowReaderFactory;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Import\Doubles\Service\Adapter\InMemoryRejectWriter;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ClosureImportFeatureTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testClosureFilePersistsIntervalsAndRejectsInvalidRows(): void
    {
        $user = UserFactory::createOne(['username' => 'closure-import-user']);
        $state = StateFactory::createOne();
        $dispatch = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Klinikum Beispiel',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        DepartmentFactory::createOne(['name' => 'Gastroenterologische Endoskopie']);
        DepartmentFactory::createOne(['name' => 'Handchirurgie']);

        $import = new Import()
            ->setName('Closure sample')
            ->setHospital($this->em->getReference(\App\Allocation\Domain\Entity\Hospital::class, $hospital->getId()))
            ->setCreatedBy($this->em->getReference(\App\User\Domain\Entity\User::class, $user->getId()))
            ->setStatus(ImportStatus::PENDING)
            ->setType(ImportType::CLOSURE)
            ->setFilePath('closure_import_sample.csv')
            ->setFileExtension('csv')
            ->setFileMimeType('text/csv')
            ->setFileSize(100)
            ->setRowCount(0)
            ->setRunCount(0)
            ->setRunTime(0);
        $this->em->persist($import);
        $this->em->flush();

        $reader = self::getContainer()->get(RowReaderFactory::class)->createFromCsvFile(
            \dirname(__DIR__, 2).'/Fixtures/closure_import_sample.csv',
        );
        $rejectWriter = new InMemoryRejectWriter();
        $summary = self::getContainer()->get(ClosureImporterFactory::class)->create($reader, $rejectWriter)->import($import);

        self::assertSame(7, $summary->total);
        self::assertSame(4, $summary->ok);
        self::assertSame(3, $summary->rejected);

        $intervals = $this->em->getRepository(ClosureInterval::class)->findBy(['import' => $import->getId()]);
        self::assertCount(4, $intervals);

        $careLevels = array_map(static fn (ClosureInterval $interval): ?string => $interval->getCareLevel()?->value, $intervals);
        self::assertContains(ClosureCareLevel::OTHER->value, $careLevels);
        self::assertContains(ClosureCareLevel::EMERGENCY->value, $careLevels);

        $reasons = array_map(static fn (ClosureInterval $interval): ?string => $interval->getReason()?->value, $intervals);
        self::assertContains(ClosureReason::NOT_SPECIFIED->value, $reasons);
        self::assertContains(ClosureReason::NO_BED_CAPACITY->value, $reasons);

        $dst = array_values(array_filter(
            $intervals,
            static fn (ClosureInterval $interval): bool => 'Handchirurgie komplett' === $interval->getClosureUnit(),
        ));
        self::assertCount(1, $dst);
        self::assertSame(1270, $dst[0]->durationInMinutes());

        $units = array_map(static fn (ClosureInterval $interval): ?string => $interval->getClosureUnit(), $intervals);
        self::assertContains('COVID Normalstation', $units);
        self::assertContains(null, $units);

        $messages = implode("\n", array_map(
            static fn (array $record): string => implode(' | ', $record['messages']),
            $rejectWriter->all(),
        ));
        self::assertStringContainsString('UNKNOWN_REASON', $messages);
        self::assertStringContainsString('HOSPITAL_MISMATCH', $messages);
        self::assertStringContainsString('REF_NOT_FOUND', $messages);
    }
}
