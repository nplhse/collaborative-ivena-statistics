<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Service;

use App\Import\Application\Contracts\ImportWorkflowDispatcherInterface;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\UnsupportedImportTypeException;
use App\Import\Application\Service\ImportStartDispatcher;
use App\Import\Domain\Entity\Import;
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
        $allocations->expects($this->once())->method('dispatch')->with(42);
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
        $closures->expects($this->once())->method('dispatch')->with(9);

        $type = $this->dispatcher($entityManager, $allocations, $closures)->dispatch(9);

        self::assertSame(ImportType::CLOSURE, $type);
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
