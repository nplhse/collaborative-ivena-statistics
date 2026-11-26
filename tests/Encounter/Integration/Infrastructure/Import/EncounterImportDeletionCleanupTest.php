<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Import;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Deleting an encounter import removes the encounters it leaves without records right away (#1644).
 *
 * Allocations X (10:00) and Y (11:00) of one IVENA import; ED import A with visits at 10:05 and 11:05, both linked
 * by the matcher. A second hospital has an encounter without records that no deletion here touches.
 */
#[ResetDatabase]
final class EncounterImportDeletionCleanupTest extends KernelTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    private Import $ivena;

    private Import $edA;

    private int $allocationX;

    private int $allocationY;

    private int $otherHospitalEmpty;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $this->ivena = $this->encounterImport($this->hospital, 'IVENA', ImportType::ALLOCATION);
        $this->allocationX = (int) $this->allocationAt($this->hospital, $this->ivena, '10:00')->getId();
        $this->allocationY = (int) $this->allocationAt($this->hospital, $this->ivena, '11:00')->getId();
        $this->edA = $this->encounterImport($this->hospital, 'ED A');
        $this->visitAt($this->hospital, $this->edA, '10:05');
        $this->visitAt($this->hospital, $this->edA, '11:05');
        $this->matchDay($this->hospital);
        self::assertSame([$this->allocationX, $this->allocationY], $this->linkedAllocations($this->hospital));

        $this->otherHospitalEmpty = $this->emptyEncounter($this->encounterHospital());
    }

    /**
     * After the deletion no encounter of the hospital is left, so none holds X or Y; a corrected file of the same
     * day is then linked completely, as in a clean run (the matcher rerun the deletion schedules runs after it).
     */
    public function testTheEmptyEncountersGoAndACorrectedFileIsLinkedCompletely(): void
    {
        $this->deletionService()->delete($this->edA);

        self::assertSame(0, $this->encounterCount($this->hospital));
        self::assertSame([], $this->linkedAllocations($this->hospital));

        $edB = $this->encounterImport($this->hospital, 'ED B');
        $this->visitAt($this->hospital, $edB, '10:05');
        $this->visitAt($this->hospital, $edB, '11:05');
        $this->matchDay($this->hospital);

        self::assertSame([$this->allocationX, $this->allocationY], $this->linkedAllocations($this->hospital));
        self::assertSame(1, $this->encounterCountById($this->otherHospitalEmpty), 'Other hospitals are not cleaned.');
    }

    public function testAnEncounterWithARecordOfAnotherImportStaysWithItsAssignment(): void
    {
        $encounterOfX = (int) $this->connection()->fetchOne('SELECT id FROM encounter WHERE allocation_id = ?', [$this->allocationX]);
        $this->createAttachedRecordTable();
        $this->attachRecord($encounterOfX, (int) $this->encounterImport($this->hospital, 'other schema')->getId());

        $this->deletionService()->delete($this->edA);

        self::assertSame([$this->allocationX], $this->linkedAllocations($this->hospital), 'X keeps its encounter; Y is free again.');
        self::assertSame(1, $this->encounterCount($this->hospital));
    }

    /**
     * Deleting an allocation import leaves encounters without records to the daily cleanup, as before.
     */
    public function testDeletingAnAllocationImportDoesNotRunTheCleanup(): void
    {
        $empty = $this->emptyEncounter($this->hospital);

        $this->deletionService()->delete($this->ivena);

        self::assertSame(1, $this->encounterCountById($empty));
    }

    private function emptyEncounter(Hospital $hospital): int
    {
        return (int) $this->connection()->fetchOne(
            "INSERT INTO encounter (hospital_id, key_generation, case_key, anchor_at, age, sex, created_at) VALUES (?, 1, ?, '2026-10-12 12:00:00', 40, 'F', '2026-10-12 12:00:00') RETURNING id",
            [$hospital->getId(), hash('sha256', 'empty-'.$hospital->getId())],
        );
    }

    /**
     * @return list<int>
     */
    private function linkedAllocations(Hospital $hospital): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn(
            'SELECT allocation_id FROM encounter WHERE hospital_id = ? AND allocation_id IS NOT NULL ORDER BY allocation_id',
            [$hospital->getId()],
        ));
    }

    private function encounterCount(Hospital $hospital): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE hospital_id = ?', [$hospital->getId()]);
    }

    private function encounterCountById(int $encounterId): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$encounterId]);
    }

    private function deletionService(): ImportDeletionService
    {
        $service = self::getContainer()->get(ImportDeletionService::class);
        self::assertInstanceOf(ImportDeletionService::class, $service);

        return $service;
    }

    private function connection(): Connection
    {
        return $this->encounterEntityManager()->getConnection();
    }
}
