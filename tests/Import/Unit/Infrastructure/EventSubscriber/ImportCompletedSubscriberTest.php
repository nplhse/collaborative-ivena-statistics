<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Import\Infrastructure\EventSubscriber\ImportCompletedSubscriber;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

#[CoversClass(ImportCompletedSubscriber::class)]
final class ImportCompletedSubscriberTest extends TestCase
{
    public function testDispatchesProjectionRebuildForCompletedImport(): void
    {
        $importId = 42;

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(
                static fn (object $message): bool => $message instanceof RebuildAllocationStatsProjection
                    && $importId === $message->importId
            ))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $subscriber = new ImportCompletedSubscriber($messageBus);
        $subscriber->onImportCompleted(new ImportCompleted($importId));
    }
}
