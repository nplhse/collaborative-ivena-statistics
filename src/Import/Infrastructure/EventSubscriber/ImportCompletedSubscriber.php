<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Import\Application\Service\ImportExportPeriodDeriver;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
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
        private ClosureRebuildRequestInterface $closureRebuildScheduler,
        private ImportExportPeriodDeriver $exportPeriodDeriver,
    ) {
    }

    public function onImportCompleted(ImportCompleted $event): void
    {
        $import = $this->entityManager->find(Import::class, $event->importId);
        if (!$import instanceof Import) {
            return;
        }
        if (ImportType::ALLOCATION === $import->getType()) {
            $this->messageBus->dispatch(new RebuildAllocationStatsProjection($event->importId));
            $hospitalId = $import->getHospital()?->getId();
            if (\is_int($hospitalId)) {
                $this->exportPeriodDeriver->refreshClosureImports($hospitalId);
            }

            return;
        }
        if (ImportType::CLOSURE === $import->getType()) {
            $hospitalId = $import->getHospital()?->getId();
            // The export period also bounds where closure coverage changed, which can finalise provisional events.
            $this->closureRebuildScheduler->requestAnalysis(
                \is_int($hospitalId) ? $hospitalId : null,
                $import->getExportStartsAt(),
                $import->getExportEndsAt(),
            );
        }
    }
}
