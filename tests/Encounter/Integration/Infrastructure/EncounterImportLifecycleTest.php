<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure;

use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Encounter\Domain\Entity\Encounter;
use App\Encounter\Domain\Enum\EncounterInsertOutcome;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Application\Service\ImportPreviousRunCleanupService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Import lifecycle of encounters (ADR 028) with one visit per day:
 *
 * - import A covers 1–15 October: visits on the 1st, 5th, 10th, 12th and 15th
 * - import B covers 10–31 October: visits on the 10th, 12th, 15th, 20th and 31st
 *
 * B's visits of the 10th, 12th and 15th are known from A and skipped; B creates the 20th and 31st.
 */
#[ResetDatabase]
final class EncounterImportLifecycleTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use Factories;

    private const array DAYS_A = ['2026-10-01', '2026-10-05', '2026-10-10', '2026-10-12', '2026-10-15'];
    private const array DAYS_B = ['2026-10-10', '2026-10-12', '2026-10-15', '2026-10-20', '2026-10-31'];

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testOverlappingImportSkipsKnownVisits(): void
    {
        $hospital = $this->encounterHospital();
        $importA = $this->encounterImport($hospital, 'A');
        $importB = $this->encounterImport($hospital, 'B');

        $outcomesA = $this->importVisits($hospital, $importA, self::DAYS_A);
        $outcomesB = $this->importVisits($hospital, $importB, self::DAYS_B);

        self::assertSame(array_fill_keys(self::DAYS_A, EncounterInsertOutcome::Inserted), $outcomesA);
        self::assertSame([
            '2026-10-10' => EncounterInsertOutcome::Duplicate,
            '2026-10-12' => EncounterInsertOutcome::Duplicate,
            '2026-10-15' => EncounterInsertOutcome::Duplicate,
            '2026-10-20' => EncounterInsertOutcome::Inserted,
            '2026-10-31' => EncounterInsertOutcome::Inserted,
        ], $outcomesB);
        self::assertSame([
            'A' => self::DAYS_A,
            'B' => ['2026-10-20', '2026-10-31'],
        ], $this->visitDaysByImport($hospital));
    }

    public function testKnownVisitIsNotChangedByLaterImport(): void
    {
        $hospital = $this->encounterHospital();
        $importA = $this->encounterImport($hospital, 'A');
        $importB = $this->encounterImport($hospital, 'B');
        $this->importVisits($hospital, $importA, ['2026-10-12']);
        $before = $this->findVisit('2026-10-12');

        // Same visit in B with a different age (a correction in a later export): not applied.
        $corrected = new Encounter(
            hospital: $hospital,
            import: $importB,
            keyGeneration: 1,
            visitFingerprint: self::fingerprintFor('2026-10-12'),
            caseKey: hash('sha256', 'case|2026-10-12'),
            age: 55,
            sex: $before->getSex(),
            times: $before->getTimes(),
        );

        self::assertSame(EncounterInsertOutcome::Duplicate, $this->encounterRepository()->insertIfNew($corrected));

        $after = $this->findVisit('2026-10-12');
        self::assertSame(54, $after->getAge());
        self::assertSame($importA->getId(), $after->getImport()->getId());
        self::assertTrue($before->getVisitUid()->equals($after->getVisitUid()));
    }

    public function testSameFingerprintInAnotherKeyGenerationOrHospitalIsANewVisit(): void
    {
        $hospital = $this->encounterHospital();
        $otherHospital = $this->encounterHospital();
        $import = $this->encounterImport($hospital, 'A');
        $otherImport = $this->encounterImport($otherHospital, 'Other');

        $repository = $this->encounterRepository();
        self::assertSame(EncounterInsertOutcome::Inserted, $repository->insertIfNew($this->visitOn($hospital, $import, '2026-10-12')));
        self::assertSame(EncounterInsertOutcome::Inserted, $repository->insertIfNew($this->visitOn($hospital, $import, '2026-10-12', keyGeneration: 2)));
        self::assertSame(EncounterInsertOutcome::Inserted, $repository->insertIfNew($this->visitOn($otherHospital, $otherImport, '2026-10-12')));
        self::assertSame(EncounterInsertOutcome::Duplicate, $repository->insertIfNew($this->visitOn($hospital, $import, '2026-10-12', keyGeneration: 2)));
    }

    public function testDeletingImportRemovesOnlyItsOwnVisitsAndKeepsAllocations(): void
    {
        $hospital = $this->encounterHospital();
        $importA = $this->encounterImport($hospital, 'A');
        $importB = $this->encounterImport($hospital, 'B');
        $allocationImport = $this->encounterImport($hospital, 'IVENA', ImportType::ALLOCATION);
        $this->importVisits($hospital, $importA, self::DAYS_A);
        $this->importVisits($hospital, $importB, self::DAYS_B);
        $this->allocationFor($allocationImport);

        $this->deletionService()->delete($importB);

        self::assertSame(['A' => self::DAYS_A], $this->visitDaysByImport($hospital));
        self::assertSame(1, AllocationFactory::count(['import' => $allocationImport]));
    }

    public function testDeletingFirstImportRemovesVisitsThatLaterImportOnlySkipped(): void
    {
        $hospital = $this->encounterHospital();
        $importA = $this->encounterImport($hospital, 'A');
        $importB = $this->encounterImport($hospital, 'B');
        $this->importVisits($hospital, $importA, self::DAYS_A);
        $this->importVisits($hospital, $importB, self::DAYS_B);

        $this->deletionService()->delete($importA);

        // Documented consequence (ADR 028): B must be reprocessed to restore the 10th, 12th and 15th.
        self::assertSame(['B' => ['2026-10-20', '2026-10-31']], $this->visitDaysByImport($hospital));
    }

    public function testReprocessingRemovesAndRecreatesOnlyItsOwnVisits(): void
    {
        $hospital = $this->encounterHospital();
        $importA = $this->encounterImport($hospital, 'A');
        $importB = $this->encounterImport($hospital, 'B');
        $this->importVisits($hospital, $importA, self::DAYS_A);
        $this->importVisits($hospital, $importB, self::DAYS_B);
        $uidOfA = $this->findVisit('2026-10-12')->getVisitUid();
        $uidOfB = $this->findVisit('2026-10-20')->getVisitUid();

        // Reprocessing B: the run first removes B's data, then processes B's file again.
        $this->cleanupService()->cleanup($importB);
        self::assertSame(['A' => self::DAYS_A], $this->visitDaysByImport($hospital));

        $outcomes = $this->importVisits($hospital, $importB, self::DAYS_B);

        self::assertCount(3, array_keys($outcomes, EncounterInsertOutcome::Duplicate, true));
        self::assertCount(2, array_keys($outcomes, EncounterInsertOutcome::Inserted, true));
        self::assertSame([
            'A' => self::DAYS_A,
            'B' => ['2026-10-20', '2026-10-31'],
        ], $this->visitDaysByImport($hospital));
        self::assertTrue($uidOfA->equals($this->findVisit('2026-10-12')->getVisitUid()), 'A\'s visit is untouched');
        self::assertFalse($uidOfB->equals($this->findVisit('2026-10-20')->getVisitUid()), 'B\'s visit is created anew');
    }

    private function findVisit(string $day): Encounter
    {
        $em = $this->encounterEntityManager();
        $em->clear();
        $visit = $em->getRepository(Encounter::class)->findOneBy(['visitFingerprint' => self::fingerprintFor($day)]);
        self::assertInstanceOf(Encounter::class, $visit);

        return $visit;
    }

    private function allocationFor(Import $import): void
    {
        $hospital = $import->getHospital();
        self::assertNotNull($hospital);

        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'dispatchArea' => $hospital->getDispatchArea(),
            'state' => $hospital->getState(),
            'speciality' => SpecialityFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'assignment' => AssignmentFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
            'indicationNormalized' => null,
            'infection' => null,
            'occasion' => null,
            'secondaryTransport' => null,
            'assessment' => null,
        ]);
    }

    private function deletionService(): ImportDeletionService
    {
        $service = self::getContainer()->get(ImportDeletionService::class);
        self::assertInstanceOf(ImportDeletionService::class, $service);

        return $service;
    }

    private function cleanupService(): ImportPreviousRunCleanupService
    {
        $service = self::getContainer()->get(ImportPreviousRunCleanupService::class);
        self::assertInstanceOf(ImportPreviousRunCleanupService::class, $service);

        return $service;
    }
}
