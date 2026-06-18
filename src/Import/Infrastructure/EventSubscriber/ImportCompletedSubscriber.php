<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: ImportCompleted::class, method: 'onImportCompleted')]
final readonly class ImportCompletedSubscriber
{
    public function __construct(
        private MessageBusInterface $messageBus,
    ) {
    }

    public function onImportCompleted(ImportCompleted $event): void
    {
        $this->messageBus->dispatch(new RebuildAllocationStatsProjection($event->importId));
    }
}
