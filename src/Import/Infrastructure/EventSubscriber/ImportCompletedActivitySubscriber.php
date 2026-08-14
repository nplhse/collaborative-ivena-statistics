<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\EventSubscriber;

use App\Import\Application\Event\ImportCompleted;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityImportMilestones;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Application\Contract\UserImportActivityProviderInterface;
use App\User\Domain\Enum\UserActivityType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ImportCompleted::class, method: 'onImportCompleted')]
final readonly class ImportCompletedActivitySubscriber
{
    public function __construct(
        private ImportRepository $importRepository,
        private UserImportActivityProviderInterface $importActivityProvider,
        private UserActivityRecorderInterface $activityRecorder,
    ) {
    }

    public function onImportCompleted(ImportCompleted $event): void
    {
        $import = $this->importRepository->find($event->importId);
        if (!$import instanceof Import) {
            return;
        }

        $status = $import->getStatus();
        if (ImportStatus::COMPLETED !== $status && ImportStatus::PARTIAL !== $status) {
            return;
        }

        $user = $import->getCreatedBy();
        $userId = $user?->getId();
        if (null === $userId) {
            return;
        }

        $count = $this->importActivityProvider->countsByUserIds([$userId])[$userId] ?? 0;
        if (!UserActivityImportMilestones::isMilestone($count)) {
            return;
        }

        $hospital = $import->getHospital();
        $hospitalName = $hospital?->getName();
        $hospitalPublicId = $hospital?->getPublicIdString();
        $metadata = ['milestone' => $count];
        if (null !== $hospitalName && '' !== $hospitalName && null !== $hospitalPublicId && '' !== $hospitalPublicId) {
            $metadata['hospitalPublicId'] = $hospitalPublicId;
            $metadata['hospitalName'] = $hospitalName;
        }

        $type = 1 === $count ? UserActivityType::FIRST_IMPORT : UserActivityType::IMPORT_MILESTONE;

        $this->activityRecorder->record(new UserActivityWrite(
            userId: $userId,
            type: $type,
            occurredAt: $import->getCreatedAt(),
            deduplicationKey: UserActivityDeduplicationKey::importMilestone($userId, $count),
            metadata: $metadata,
        ));
    }
}
