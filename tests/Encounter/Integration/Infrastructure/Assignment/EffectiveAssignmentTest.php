<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure\Assignment;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Contract\Assignment\EncounterAllocationAssignmentsInterface;
use App\Import\Application\Service\ImportDeletionService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * `encounter.allocation_id` is the effective assignment (#1300): the core service writes it, the ED matching
 * writes its history in `encounter_match` and the assignment in the same transaction.
 */
#[ResetDatabase]
final class EffectiveAssignmentTest extends KernelTestCase
{
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    private Import $ivena;

    private Import $ed;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $this->ivena = $this->encounterImport($this->hospital, 'IVENA', ImportType::ALLOCATION);
        $this->ed = $this->encounterImport($this->hospital, 'ED');
    }

    public function testAnAutomaticLinkIsTheEncountersAssignment(): void
    {
        $allocation = (int) $this->allocationAt($this->hospital, $this->ivena, '10:00')->getId();
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');

        $this->matchDay($this->hospital);

        self::assertSame([$visit => $allocation], $this->assignments());
        self::assertSame($visit, $this->core()->encounterOfAllocation($allocation));
    }

    public function testConfirmChangeAndRejectMoveTheAssignmentWithTheHistory(): void
    {
        // A at 10:00 is linked automatically to the visit at 10:05; the visit at 10:40 (a year older) stays a
        // weaker candidate.
        $allocation = (int) $this->allocationAt($this->hospital, $this->ivena, '10:00')->getId();
        $first = $this->visitAt($this->hospital, $this->ed, '10:05');
        $second = $this->visitAt($this->hospital, $this->ed, '10:40', age: 71);
        $this->matchDay($this->hospital);
        self::assertSame([$first => $allocation], $this->assignments());

        $replaced = $this->decider()->confirm((int) $this->hospital->getId(), $allocation, $second, $this->reviewer());

        self::assertSame($first, $replaced);
        self::assertSame([$second => $allocation], $this->assignments(), 'A change moves the assignment.');
        self::assertNotNull($this->connection()->fetchOne('SELECT allocation_assigned_by_id FROM encounter WHERE id = ?', [$second]));

        $this->decider()->reject((int) $this->hospital->getId(), $allocation, $second, $this->reviewer());

        self::assertSame([], $this->assignments(), 'A rejected link frees both sides.');
    }

    public function testASwapOfTwoTakenAllocationsKeepsTheUniqueIndex(): void
    {
        $a = (int) $this->allocationAt($this->hospital, $this->ivena, '10:00')->getId();
        $b = (int) $this->allocationAt($this->hospital, $this->ivena, '14:00')->getId();
        $first = $this->visitAt($this->hospital, $this->ed, '10:05');
        $second = $this->visitAt($this->hospital, $this->ed, '14:05');
        $this->core()->apply([$first => $a, $second => $b], null);

        $this->connection()->transactional(function () use ($first, $second, $a, $b): void {
            $this->core()->apply([$first => $b, $second => $a], null);
        });

        self::assertSame([$first => $b, $second => $a], $this->assignments());
    }

    public function testAnUnchangedAssignmentKeepsItsTimeAndATakenAllocationIsTakenAway(): void
    {
        $a = (int) $this->allocationAt($this->hospital, $this->ivena, '10:00')->getId();
        $first = $this->visitAt($this->hospital, $this->ed, '10:05');
        $second = $this->visitAt($this->hospital, $this->ed, '10:30');
        $this->core()->apply([$first => $a], null);
        $this->connection()->executeStatement("UPDATE encounter SET allocation_assigned_at = '2026-10-12 11:00:00' WHERE id = ?", [$first]);

        $this->core()->apply([$first => $a], null);
        self::assertSame('2026-10-12 11:00:00', $this->connection()->fetchOne('SELECT allocation_assigned_at FROM encounter WHERE id = ?', [$first]));

        $this->core()->apply([$second => $a], null);
        self::assertSame([$second => $a], $this->assignments());
    }

    public function testDeletingTheIvenaImportKeepsTheEncounterWithoutAssignment(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->matchDay($this->hospital);
        self::assertSame([$visit => (int) $allocation->getId()], $this->assignments());

        $this->deletionService()->delete($this->ivena);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter WHERE id = ?', [$visit]));
        self::assertSame([], $this->assignments());
    }

    public function testReprocessingTheIvenaImportRestoresAnUnambiguousAssignment(): void
    {
        $allocation = $this->allocationAt($this->hospital, $this->ivena, '10:00');
        $visit = $this->visitAt($this->hospital, $this->ed, '10:05');
        $this->matchDay($this->hospital);
        $this->connection()->executeStatement('UPDATE encounter SET allocation_id = NULL WHERE id = ?', [$visit]);
        $this->connection()->executeStatement("DELETE FROM encounter_match WHERE status = 'auto_accepted'");

        // Same allocation, recreated by the reprocess: the matcher links it again.
        $this->core()->releaseAllocationsOfImport((int) $this->ivena->getId());
        $this->matchDay($this->hospital);

        self::assertSame([$visit => (int) $allocation->getId()], $this->assignments());
    }

    /**
     * @return array<int, int> allocation by encounter, assigned encounters only
     */
    private function assignments(): array
    {
        /** @var array<int|string, int|string> $rows */
        $rows = $this->connection()->fetchAllKeyValue('SELECT id, allocation_id FROM encounter WHERE allocation_id IS NOT NULL ORDER BY id');

        $result = [];
        foreach ($rows as $encounter => $allocation) {
            $result[(int) $encounter] = (int) $allocation;
        }

        return $result;
    }

    private function reviewer(): User
    {
        return UserFactory::createOne();
    }

    private function core(): EncounterAllocationAssignmentsInterface
    {
        $service = self::getContainer()->get(EncounterAllocationAssignmentsInterface::class);
        self::assertInstanceOf(EncounterAllocationAssignmentsInterface::class, $service);

        return $service;
    }

    private function decider(): MatchDeciderInterface
    {
        $service = self::getContainer()->get(MatchDeciderInterface::class);
        self::assertInstanceOf(MatchDeciderInterface::class, $service);

        return $service;
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
