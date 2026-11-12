<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Contracts\ImportTypeWorkflowDispatcherInterface;
use App\Import\Application\Contracts\ImportWorkflowDispatcherInterface;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Application\Service\ImportStartDispatcher;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ImportStartDispatcherTest extends TestCase
{
    public function testAllocationImportDelegatesOnlyToAllocationDispatcher(): void
    {
        $import = new Import();
        $import->setType(ImportType::ALLOCATION);
        $entityManager = $this->entityManagerReturning(42, $import);
        $allocations = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $allocations->expects($this->once())->method('dispatch')->with(42, ImportRunTrigger::Console, null);
        $closures = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $closures->expects($this->never())->method('dispatch');

        $type = $this->dispatcher($entityManager, $allocations, $closures)->dispatch(42);

        self::assertSame(ImportType::ALLOCATION, $type);
    }

    public function testClosureImportDelegatesOnlyToClosureDispatcher(): void
    {
        $import = new Import();
        $import->setType(ImportType::CLOSURE);
        $entityManager = $this->entityManagerReturning(9, $import);
        $allocations = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $allocations->expects($this->never())->method('dispatch');
        $closures = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $closures->expects($this->once())->method('dispatch')->with(9, ImportRunTrigger::Console, null);

        $type = $this->dispatcher($entityManager, $allocations, $closures)->dispatch(9);

        self::assertSame(ImportType::CLOSURE, $type);
    }

    public function testTriggerAndInitiatorArePassedToTheWorkflowDispatcher(): void
    {
        $import = new Import();
        $import->setType(ImportType::ALLOCATION);
        $entityManager = $this->entityManagerReturning(42, $import);
        $allocations = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $allocations->expects($this->once())->method('dispatch')->with(42, ImportRunTrigger::Reprocess, 5);
        $closures = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $closures->expects($this->never())->method('dispatch');

        $this->dispatcher($entityManager, $allocations, $closures)->dispatch(42, 5, ImportRunTrigger::Reprocess);
    }

    public function testMissingImportThrowsNotFound(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('find')->with(Import::class, 15)->willReturn(null);
        $allocations = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $allocations->expects($this->never())->method('dispatch');
        $closures = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $closures->expects($this->never())->method('dispatch');

        $this->expectException(ImportNotFoundException::class);
        $this->expectExceptionMessage('No Import found with ID 15');

        $this->dispatcher($entityManager, $allocations, $closures)->dispatch(15);
    }

    public function testImportWithoutTypeThrowsUnsupportedImportType(): void
    {
        $entityManager = $this->entityManagerReturning(7, new Import());
        $allocations = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $allocations->expects($this->never())->method('dispatch');
        $closures = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $closures->expects($this->never())->method('dispatch');

        $this->expectException(UnsupportedImportTypeException::class);
        $this->expectExceptionMessage('Import #7 has no type and cannot be started.');

        $this->dispatcher($entityManager, $allocations, $closures)->dispatch(7);
    }

    public function testEncounterImportDelegatesToTheTaggedDispatcherOfItsType(): void
    {
        $import = new Import();
        $import->setType(ImportType::ENCOUNTER);
        $entityManager = $this->entityManagerReturning(11, $import);
        $allocations = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $allocations->expects($this->never())->method('dispatch');
        $closures = $this->createMock(ImportWorkflowDispatcherInterface::class);
        $closures->expects($this->never())->method('dispatch');
        $encounters = new RecordingEncounterDispatcher();

        $type = new ImportStartDispatcher($entityManager, $allocations, $closures, [$encounters])->dispatch(11, 3, ImportRunTrigger::Reprocess);

        self::assertSame(ImportType::ENCOUNTER, $type);
        self::assertSame([[11, ImportRunTrigger::Reprocess, 3]], $encounters->calls);
    }

    public function testTypeWithoutRegisteredDispatcherThrowsUnsupportedImportType(): void
    {
        $import = new Import();
        $import->setType(ImportType::ENCOUNTER);
        $entityManager = $this->entityManagerReturning(12, $import);

        $this->expectException(UnsupportedImportTypeException::class);
        $this->expectExceptionMessage('Import #12 has type "Encounter", but no workflow dispatcher is registered for it.');

        $this->dispatcher($entityManager, $this->createStub(ImportWorkflowDispatcherInterface::class), $this->createStub(ImportWorkflowDispatcherInterface::class))->dispatch(12);
    }

    private function entityManagerReturning(int $importId, Import $import): EntityManagerInterface
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())->method('find')->with(Import::class, $importId)->willReturn($import);

        return $entityManager;
    }

    private function dispatcher(
        EntityManagerInterface $entityManager,
        ImportWorkflowDispatcherInterface $allocations,
        ImportWorkflowDispatcherInterface $closures,
    ): ImportStartDispatcher {
        return new ImportStartDispatcher($entityManager, $allocations, $closures);
    }
}

final class RecordingEncounterDispatcher implements ImportTypeWorkflowDispatcherInterface
{
    /** @var list<array{int, ImportRunTrigger, int|null}> */
    public array $calls = [];

    #[\Override]
    public static function importType(): ImportType
    {
        return ImportType::ENCOUNTER;
    }

    #[\Override]
    public function dispatch(int $importId, ImportRunTrigger $trigger, ?int $initiatedByUserId = null, ?ImportBatchRunItem $batchRunItem = null): void
    {
        $this->calls[] = [$importId, $trigger, $initiatedByUserId];
    }
}
