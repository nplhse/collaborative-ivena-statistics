<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\EventSubscriber;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Import\Application\Event\ImportCompleted;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class ImportCompletedAnalyticsSubscriber
{
    public function __construct(
        private UsageAnalytics $usageAnalytics,
        private ImportRepository $importRepository,
    ) {
    }

    #[AsEventListener(event: ImportCompleted::class)]
    public function onImportCompleted(ImportCompleted $event): void
    {
        $import = $this->importRepository->find($event->importId);
        if (!$import instanceof Import) {
            return;
        }

        $user = $import->getCreatedBy();
        if (!$user instanceof User) {
            return;
        }

        $this->usageAnalytics->recordForUser(
            UsageEventName::IMPORT_COMPLETED,
            $user,
            FeatureArea::Import,
        );
    }
}
