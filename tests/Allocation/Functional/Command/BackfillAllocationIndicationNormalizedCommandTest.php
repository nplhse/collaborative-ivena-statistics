<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\UI\Console\Command\BackfillAllocationIndicationNormalizedCommand;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillAllocationIndicationNormalizedCommandTest extends KernelTestCase
{
    use Factories;

    public function testDryRunShowsNoteAndDoesNotPersist(): void
    {
        self::bootKernel();
        $this->seedAllocationNeedingBackfill();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Dry run: no rows will be written.', $display);
        self::assertStringContainsString('Dry run finished. Re-run without --dry-run to apply changes.', $display);
        self::assertStringNotContainsString('Backfill finished in', $display);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertGreaterThanOrEqual(
            1,
            (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM allocation WHERE indication_normalized_id IS NULL',
            ),
        );
    }

    public function testBackfillWithoutProjectionRebuildShowsHint(): void
    {
        self::bootKernel();
        $fixture = $this->seedAllocationNeedingBackfill();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Backfill allocation indication_normalized', $display);
        self::assertStringContainsString('indication_raw.normalized_id', $display);
        self::assertStringContainsString('Projection not rebuilt.', $display);
        self::assertStringContainsString('app:statistics:rebuild-projection', $display);
        self::assertStringContainsString('Backfill finished in', $display);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $allocationNormalizedId = $connection->fetchOne(
            'SELECT indication_normalized_id FROM allocation WHERE id = :id',
            ['id' => $fixture['allocationId']],
        );
        self::assertSame($fixture['normalizedId'], (int) $allocationNormalizedId);
    }

    public function testBackfillWithProjectionRebuild(): void
    {
        self::bootKernel();
        $fixture = $this->seedAllocationNeedingBackfill();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--rebuild-projection' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $tester->getDisplay();
        self::assertStringContainsString('Rebuilding allocation_stats_projection for 1 import(s)', $display);
        self::assertStringNotContainsString('Projection not rebuilt.', $display);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $projectionNormalizedId = $connection->fetchOne(
            'SELECT indication_normalized_id FROM allocation_stats_projection WHERE id = :id',
            ['id' => $fixture['allocationId']],
        );
        self::assertSame($fixture['normalizedId'], (int) $projectionNormalizedId);
    }

    public function testRebuildProjectionSkippedWhenNoAllocationsExist(): void
    {
        self::bootKernel();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--rebuild-projection' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No allocations found; projection rebuild skipped.', $tester->getDisplay());
    }

    public function testSkipRawSyncSkipsRawNormalizedCopy(): void
    {
        self::bootKernel();
        $fixture = $this->seedAllocationNeedingBackfill();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--skip-raw-sync' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertNull(
            $connection->fetchOne(
                'SELECT normalized_id FROM indication_raw WHERE id = :id',
                ['id' => $fixture['rawId']],
            ),
        );
        self::assertSame(
            $fixture['normalizedId'],
            (int) $connection->fetchOne(
                'SELECT indication_normalized_id FROM allocation WHERE id = :id',
                ['id' => $fixture['allocationId']],
            ),
        );
    }

    public function testSkipAllocationsSkipsAllocationColumns(): void
    {
        self::bootKernel();
        $fixture = $this->seedAllocationNeedingBackfill();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--skip-allocations' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        self::assertNull(
            $connection->fetchOne(
                'SELECT indication_normalized_id FROM allocation WHERE id = :id',
                ['id' => $fixture['allocationId']],
            ),
        );
        self::assertSame(
            $fixture['normalizedId'],
            (int) $connection->fetchOne(
                'SELECT normalized_id FROM indication_raw WHERE id = :id',
                ['id' => $fixture['rawId']],
            ),
        );
    }

    private function createCommandTester(): CommandTester
    {
        $command = self::getContainer()->get(BackfillAllocationIndicationNormalizedCommand::class);

        return new CommandTester($command);
    }

    /**
     * @return array{allocationId: int, rawId: int, normalizedId: int}
     */
    private function seedAllocationNeedingBackfill(): array
    {
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Cmd Backfill Norm', 'code' => 99101]);
        $raw = IndicationRawFactory::createOne([
            'code' => 99101,
            'name' => 'Cmd Backfill Raw',
            'target' => $normalized,
        ]);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            'UPDATE indication_raw SET normalized_id = NULL WHERE id = :id',
            ['id' => $raw->getId()],
        );

        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdBy' => $user,
        ]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $user]);

        SpecialityFactory::createOne();
        DepartmentFactory::createOne();
        AssignmentFactory::createOne();
        OccasionFactory::createOne();

        $allocation = AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'createdAt' => new \DateTimeImmutable('2025-04-01 08:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2025-04-01 08:30:00'),
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'transportType' => AllocationTransportType::GROUND,
            'infection' => null,
            'secondaryTransport' => null,
            'indicationRaw' => $raw,
            'indicationNormalized' => null,
        ]);

        return [
            'allocationId' => (int) $allocation->getId(),
            'rawId' => (int) $raw->getId(),
            'normalizedId' => (int) $normalized->getId(),
        ];
    }
}
