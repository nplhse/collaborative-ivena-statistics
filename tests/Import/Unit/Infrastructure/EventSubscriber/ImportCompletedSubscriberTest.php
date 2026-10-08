<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\EventSubscriber\ImportCompletedSubscriber;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ImportCompletedSubscriber::class)]
final class ImportCompletedSubscriberTest extends TestCase
{
    public function testDispatchesProjectionRebuildForCompletedAllocationImport(): void
    {
        $importId = 42;
        $import = $this->createStub(Import::class);
        $import->method('getType')->willReturn(ImportType::ALLOCATION);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('find')
            ->with(Import::class, $importId)
            ->willReturn($import);

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn (object $message): bool => $message instanceof RebuildAllocationStatsProjection
                    && $importId === $message->importId
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $subscriber = new ImportCompletedSubscriber($messageBus, $entityManager, $this->createStub(ClosureRebuildRequestInterface::class));
        $subscriber->onImportCompleted(new ImportCompleted($importId));
    }

    public function testRequestsAnalysisForClosureImport(): void
    {
        $hospital = $this->createStub(\App\Allocation\Domain\Entity\Hospital::class);
        $hospital->method('getId')->willReturn(4);
        $import = $this->createStub(Import::class);
        $import->method('getType')->willReturn(ImportType::CLOSURE);
        $import->method('getHospital')->willReturn($hospital);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($import);

        $scheduler = $this->createMock(ClosureRebuildRequestInterface::class);
        $scheduler->expects($this->once())->method('requestAnalysis')->with(4);

        $subscriber = new ImportCompletedSubscriber($this->createStub(MessageBusInterface::class), $entityManager, $scheduler);
        $subscriber->onImportCompleted(new ImportCompleted(7));
    }
}
