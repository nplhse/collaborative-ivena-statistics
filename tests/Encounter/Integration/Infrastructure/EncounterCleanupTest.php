<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Integration\Infrastructure;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Cleanup\EncounterCleanupResult;
use App\Encounter\Application\Contract\EncounterCleanupInterface;
use App\Encounter\Application\Message\CleanUpEncountersMessage;
use App\Encounter\Infrastructure\Scheduler\EncounterScheduleContribution;
use App\Import\Domain\Enum\ImportType;
use App\Tests\Support\Encounter\AttachedRecordFixtures;
use App\Tests\Support\Encounter\EncounterDataFixtures;
use App\Tests\Support\Encounter\MatchingDataFixtures;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Cleanup of encounters without clinical records (#1300, ADR 037). Visit A at 10:05 is linked to allocation X;
 * the encounters "empty" and "empty with X2" have no record (the second still carries allocation X2).
 */
#[ResetDatabase]
final class EncounterCleanupTest extends KernelTestCase
{
    use AttachedRecordFixtures;
    use EncounterDataFixtures;
    use Factories;
    use MatchingDataFixtures;

    private Hospital $hospital;

    /** @var array<string, int> */
    private array $encounters = [];

    private int $allocationX2;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->hospital = $this->encounterHospital();
        $ivena = $this->encounterImport($this->hospital, 'IVENA', ImportType::ALLOCATION);
        $this->allocationAt($this->hospital, $ivena, '10:00');
        $this->allocationX2 = (int) $this->allocationAt($this->hospital, $ivena, '18:00')->getId();
        $this->encounters['A'] = $this->visitAt($this->hospital, $this->encounterImport($this->hospital, 'ED'), '10:05');
        $this->matchDay($this->hospital);
        $this->encounters['empty'] = $this->emptyEncounter('empty');
        $this->encounters['empty with X2'] = $this->emptyEncounter('empty-x2', $this->allocationX2);
    }

    public function testEncountersWithoutRecordsGoAlsoWithAnAssignment(): void
    {
        $result = $this->cleanup()->run((int) $this->hospital->getId());

        self::assertEquals(new EncounterCleanupResult(hospitals: 1, removed: 2, removedWithAssignment: 1), $result);
        self::assertSame([$this->encounters['A']], $this->remainingEncounters());
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM allocation WHERE id = ?', [$this->allocationX2]), 'The IVENA allocation stays.');
    }

    public function testARecordOfAnotherSchemaKeepsItsEncounter(): void
    {
        $this->createAttachedRecordTable();
        $this->attachRecord($this->encounters['empty'], (int) $this->encounterImport($this->hospital, 'other', ImportType::ALLOCATION)->getId());

        $result = $this->cleanup()->run();

        self::assertSame(1, $result->removed);
        self::assertSame([$this->encounters['A'], $this->encounters['empty']], $this->remainingEncounters());
    }

    public function testTheMatchingHistoryOfARemovedEncounterGoesWithIt(): void
    {
        $this->connection()->executeStatement('DELETE FROM ed_visit WHERE encounter_id = ?', [$this->encounters['A']]);
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_match WHERE encounter_id = ?', [$this->encounters['A']]));

        $this->cleanup()->run();

        self::assertSame([], $this->remainingEncounters());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM encounter_match'));
    }

    public function testTheCommandAndTheScheduledMessageRunTheCleanup(): void
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:encounter:clean-up'));
        self::assertSame(0, $tester->execute(['--hospital' => (string) $this->hospital->getId()]));
        self::assertStringContainsString('Removed the encounters without clinical records.', $tester->getDisplay());
        self::assertSame([$this->encounters['A']], $this->remainingEncounters());

        $this->connection()->executeStatement('DELETE FROM ed_visit WHERE encounter_id = ?', [$this->encounters['A']]);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new CleanUpEncountersMessage());
        self::assertSame([], $this->remainingEncounters());

        $schedule = new EncounterScheduleContribution();
        self::assertSame(EncounterScheduleContribution::JOB_CLEANUP, $schedule->recordedJobs()[CleanUpEncountersMessage::class]);
    }

    public function testANegativeHospitalIsRefused(): void
    {
        $tester = new CommandTester(new Application(self::$kernel)->find('app:encounter:clean-up'));

        self::assertSame(2, $tester->execute(['--hospital' => '-1']));
    }

    private function emptyEncounter(string $case, ?int $allocationId = null): int
    {
        return (int) $this->connection()->fetchOne(
            "INSERT INTO encounter (hospital_id, key_generation, case_key, anchor_at, age, sex, created_at, allocation_id) VALUES (?, 1, ?, '2026-10-12 12:00:00', 40, 'F', '2026-10-12 12:00:00', ?) RETURNING id",
            [$this->hospital->getId(), hash('sha256', $case), $allocationId],
        );
    }

    /**
     * @return list<int>
     */
    private function remainingEncounters(): array
    {
        return array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM encounter WHERE hospital_id = ? ORDER BY id', [$this->hospital->getId()]));
    }

    private function cleanup(): EncounterCleanupInterface
    {
        $cleanup = self::getContainer()->get(EncounterCleanupInterface::class);
        self::assertInstanceOf(EncounterCleanupInterface::class, $cleanup);

        return $cleanup;
    }

    private function connection(): Connection
    {
        return $this->encounterEntityManager()->getConnection();
    }
}
