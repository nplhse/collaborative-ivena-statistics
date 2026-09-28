<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Message\RebuildAllocationStatsProjection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsEventListener(event: ImportCompleted::class, method: 'onImportCompleted')]
final readonly class ImportCompletedSubscriber
{
    public function __construct(
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function onImportCompleted(ImportCompleted $event): void
    {
        $import = $this->entityManager->find(Import::class, $event->importId);
        if (!$import instanceof Import || ImportType::ALLOCATION !== $import->getType()) {
            return;
        }

        $this->messageBus->dispatch(new RebuildAllocationStatsProjection($event->importId));
    }
}
