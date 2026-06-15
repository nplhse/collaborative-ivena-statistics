<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportFailed;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationSenderInterface;
use App\Shared\Application\Notification\AdminNotificationType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AsEventListener(event: ImportFailed::class, method: 'onImportFailed')]
final readonly class ImportFailedNotificationSubscriber
{
    /** @psalm-suppress PossiblyUnusedMethod */
    public function __construct(
        private ImportRepository $importRepository,
        private AdminNotificationSenderInterface $adminNotificationSender,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onImportFailed(ImportFailed $event): void
    {
        $import = $this->importRepository->find($event->importId);
        if (!$import instanceof Import) {
            return;
        }

        $importId = $import->getId();
        if (null === $importId) {
            return;
        }

        $hospital = $import->getHospital();

        $status = $import->getStatus();

        $this->adminNotificationSender->send(new AdminNotification(
            type: AdminNotificationType::ImportFailed,
            templateContext: [
                'importName' => $import->getName() ?? '—',
                'hospitalName' => $hospital instanceof \App\Allocation\Domain\Entity\Hospital ? (string) $hospital : '—',
                'status' => $status instanceof \App\Import\Domain\Enum\ImportStatus ? $status->value : '—',
                'rowCount' => $import->getRowCount(),
                'runTimeMs' => $import->getRunTime(),
                'reason' => $event->reason,
                'importDetailUrl' => $this->urlGenerator->generate(
                    'app_import_show',
                    ['id' => $importId],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
            ],
            referenceId: (string) $importId,
        ));
    }
}
