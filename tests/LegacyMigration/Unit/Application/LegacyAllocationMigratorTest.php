<?php

declare(strict_types=1);

namespace App\Tests\LegacyMigration\Unit\Application;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationPersisterInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Entity\Import;
use App\LegacyMigration\Application\Contract\LegacyAllocationImportFactoryInterface;
use App\LegacyMigration\Application\Contract\NullLegacyMigrationProgress;
use App\LegacyMigration\Application\Exception\LegacyMigrationInterruptedException;
use App\LegacyMigration\Application\Service\LegacyAllocationMigrator;
use App\LegacyMigration\Application\Service\LegacyMigrationRunControl;
use App\LegacyMigration\Domain\Repository\LegacyMigrationStateRepositoryInterface;
use App\LegacyMigration\Infrastructure\Mapper\LegacyAllocationRowMapper;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class LegacyAllocationMigratorTest extends TestCase
{
    public function testFlushesOncePerBatchNotPerRow(): void
    {
        $persister = new RecordingAllocationPersister();
        $factory = new StubAllocationImportFactory([
            $this->createAllocationWithId(101),
            $this->createAllocationWithId(102),
        ]);

        $migrator = $this->createMigrator(
            persister: $persister,
            factory: $factory,
            legacyRows: [
                $this->minimalLegacyRow(1),
                $this->minimalLegacyRow(2),
            ],
        );

        $migrated = $migrator->migrate(
            dryRun: false,
            batchSize: 500,
            resume: false,
            progress: new NullLegacyMigrationProgress(),
            runControl: new LegacyMigrationRunControl(),
        );

        self::assertSame(2, $migrated);
        self::assertSame(2, $persister->persistCount);
        self::assertSame(1, $persister->flushCount);
        self::assertSame(1, $factory->warmCount);
    }

    public function testSkipProjectionSkipsRebuild(): void
    {
        $projectionRebuilder = $this->createMock(AllocationStatsProjectionRebuildInterface::class);
        $projectionRebuilder->expects(self::never())->method('rebuildForImport');

        $migrator = $this->createMigrator(
            persister: new RecordingAllocationPersister(),
            factory: new StubAllocationImportFactory([$this->createAllocationWithId(101)]),
            legacyRows: [$this->minimalLegacyRow(1)],
            projectionRebuilder: $projectionRebuilder,
        );

        $migrator->migrate(
            dryRun: false,
            batchSize: 500,
            resume: false,
            progress: new NullLegacyMigrationProgress(),
            runControl: new LegacyMigrationRunControl(),
            skipProjection: true,
        );
    }

    public function testInterruptDoesNotMarkImportAsFailed(): void
    {
        $runControl = new LegacyMigrationRunControl();
        $runControl->requestStop(\SIGINT);

        $legacyConnection = $this->createMock(Connection::class);
        $defaultConnection = $this->createMock(Connection::class);

        $defaultConnection->method('fetchAllAssociative')->willReturn([
            [
                'legacy_import_id' => 1,
                'new_import_id' => 10,
                'status' => 'pending',
                'last_allocation_id' => 0,
                'migrated_count' => 0,
            ],
        ]);
        $defaultConnection->expects(self::never())->method('update')->with(
            self::anything(),
            self::callback(static fn (array $data): bool => ($data['status'] ?? '') === 'failed'),
            self::anything(),
        );

        $legacyConnection->method('fetchAllAssociative')
            ->willReturn([['import_id' => 1, 'total' => 2]]);

        $migrator = $this->createMigratorWithConnections(
            $legacyConnection,
            $defaultConnection,
            new StubAllocationImportFactory([]),
            new RecordingAllocationPersister(),
        );

        $this->expectException(LegacyMigrationInterruptedException::class);
        $migrator->migrate(
            dryRun: false,
            batchSize: 500,
            resume: false,
            progress: new NullLegacyMigrationProgress(),
            runControl: $runControl,
        );
    }

    public function testSkipsDoneImportsWithoutForce(): void
    {
        $legacyConnection = $this->createMock(Connection::class);
        $defaultConnection = $this->createMock(Connection::class);

        $defaultConnection->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                self::assertStringContainsString("status IN ('pending', 'running', 'failed')", $sql);

                return [];
            });
        $legacyConnection->method('fetchAllAssociative')
            ->willReturn([]);
        $legacyConnection->expects(self::never())->method('executeQuery');

        $migrator = $this->createMigratorWithConnections(
            $legacyConnection,
            $defaultConnection,
            new StubAllocationImportFactory([]),
            new RecordingAllocationPersister(),
        );

        $migrated = $migrator->migrate(
            dryRun: false,
            batchSize: 500,
            resume: false,
            progress: new NullLegacyMigrationProgress(),
            runControl: new LegacyMigrationRunControl(),
            force: false,
        );

        self::assertSame(0, $migrated);
    }

    public function testFastPathSkipsLegacyBatchQueryForCompleteDoneImport(): void
    {
        $legacyConnection = $this->createMock(Connection::class);
        $defaultConnection = $this->createMock(Connection::class);

        $defaultConnection->method('fetchAllAssociative')->willReturn([
            [
                'legacy_import_id' => 1,
                'new_import_id' => 10,
                'status' => 'done',
                'last_allocation_id' => 99,
                'migrated_count' => 2,
            ],
        ]);
        $defaultConnection->method('update');
        $legacyConnection->method('fetchAllAssociative')
            ->willReturn([['import_id' => 1, 'total' => 2]]);

        $legacyConnection->expects(self::never())->method('executeQuery');

        $factory = new StubAllocationImportFactory([]);
        $migrator = $this->createMigratorWithConnections(
            $legacyConnection,
            $defaultConnection,
            $factory,
            new RecordingAllocationPersister(),
        );

        $migrated = $migrator->migrate(
            dryRun: false,
            batchSize: 500,
            resume: false,
            progress: new NullLegacyMigrationProgress(),
            runControl: new LegacyMigrationRunControl(),
            force: true,
        );

        self::assertSame(0, $migrated);
        self::assertSame(1, $factory->warmCount);
    }

    /**
     * @param list<array<string, mixed>> $legacyRows
     */
    private function createMigrator(
        RecordingAllocationPersister $persister,
        StubAllocationImportFactory $factory,
        array $legacyRows,
        ?AllocationStatsProjectionRebuildInterface $projectionRebuilder = null,
    ): LegacyAllocationMigrator {
        $legacyConnection = $this->createMock(Connection::class);
        $defaultConnection = $this->createMock(Connection::class);

        $defaultConnection->method('fetchAllAssociative')->willReturn([
            [
                'legacy_import_id' => 1,
                'new_import_id' => 10,
                'status' => 'pending',
                'last_allocation_id' => 0,
                'migrated_count' => 0,
            ],
        ]);
        $defaultConnection->method('beginTransaction');
        $defaultConnection->method('commit');
        $defaultConnection->method('isTransactionActive')->willReturn(false);
        $defaultConnection->method('update');
        $defaultConnection->method('executeStatement');

        $batchResult = $this->createBatchResult($legacyRows);
        $emptyResult = $this->createBatchResult([]);
        $legacyConnection->method('executeQuery')->willReturnOnConsecutiveCalls($batchResult, $emptyResult);
        $legacyConnection->method('fetchAllAssociative')
            ->willReturn([['import_id' => 1, 'total' => 2]]);
        $legacyConnection->method('fetchOne')->willReturn('2');

        return $this->createMigratorWithConnections(
            $legacyConnection,
            $defaultConnection,
            $factory,
            $persister,
            $projectionRebuilder,
        );
    }

    private function createMigratorWithConnections(
        Connection&MockObject $legacyConnection,
        Connection&MockObject $defaultConnection,
        StubAllocationImportFactory $factory,
        RecordingAllocationPersister $persister,
        ?AllocationStatsProjectionRebuildInterface $projectionRebuilder = null,
    ): LegacyAllocationMigrator {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $validator = $this->createMock(ValidatorInterface::class);
        $stateRepository = $this->createMock(LegacyMigrationStateRepositoryInterface::class);
        $auditContext = new AuditContext();

        $import = $this->createMock(Import::class);
        $entityManager->method('getReference')->with(Import::class, 10)->willReturn($import);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        $projectionRebuilder ??= $this->createMock(AllocationStatsProjectionRebuildInterface::class);

        return new LegacyAllocationMigrator(
            $legacyConnection,
            $defaultConnection,
            $entityManager,
            new LegacyAllocationRowMapper(),
            $factory,
            $persister,
            $validator,
            $projectionRebuilder,
            $stateRepository,
            $auditContext,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function createBatchResult(array $rows): Result&MockObject
    {
        $result = $this->createMock(Result::class);
        $result->method('iterateAssociative')->willReturn($this->rowsGenerator($rows));
        $result->expects(self::once())->method('free');

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function rowsGenerator(array $rows): \Generator
    {
        foreach ($rows as $row) {
            yield $row;
        }
    }

    private function createAllocationWithId(int $id): Allocation&MockObject
    {
        $allocation = $this->createMock(Allocation::class);
        $allocation->method('getId')->willReturn($id);

        return $allocation;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalLegacyRow(int $id): array
    {
        return [
            'id' => $id,
            'hospital_name' => 'Test Hospital',
            'dispatch_area_name' => 'Area',
            'created_at' => '2024-01-01 10:00:00',
            'arrival_at' => null,
            'gender' => 'm',
            'age' => 30,
            'requires_resus' => 0,
            'requires_cathlab' => 0,
            'is_cpr' => 0,
            'is_ventilated' => 0,
            'is_shock' => 0,
            'is_pregnant' => 0,
            'is_work_accident' => 0,
            'is_with_physician' => 0,
            'mode_of_transport' => 'RTW',
            'urgency' => 1,
            'speciality' => 'Innere',
            'speciality_detail' => null,
            'speciality_was_closed' => 0,
            'assignment' => null,
            'occasion' => null,
            'secondary_deployment' => null,
            'is_infectious' => null,
            'indication_code' => 100,
            'indication' => 'Test',
            'secondary_indication_code' => null,
            'secondary_indication' => null,
        ];
    }
}

final class RecordingAllocationPersister implements AllocationPersisterInterface
{
    public int $persistCount = 0;

    public int $flushCount = 0;

    #[\Override]
    public function persist(object $entity): void
    {
        ++$this->persistCount;
    }

    #[\Override]
    public function flush(): void
    {
        ++$this->flushCount;
    }
}

final class StubAllocationImportFactory implements LegacyAllocationImportFactoryInterface
{
    public int $warmCount = 0;

    /**
     * @param list<Allocation> $allocations
     */
    public function __construct(private array $allocations)
    {
    }

    #[\Override]
    public function warm(): void
    {
        ++$this->warmCount;
    }

    #[\Override]
    public function fromDto(AllocationRowDTO $dto, Import $import): Allocation
    {
        if ([] === $this->allocations) {
            throw new \RuntimeException('No allocation stub left');
        }

        return array_shift($this->allocations);
    }
}
