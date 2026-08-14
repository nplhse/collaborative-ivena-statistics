<?php

declare(strict_types=1);

namespace App\Allocation\Infrastructure\EventSubscriber;

use App\Allocation\Application\Event\HospitalOwnershipGranted;
use App\Allocation\Application\Event\HospitalOwnershipRevoked;
use App\Allocation\Application\Event\UserAssociatedWithHospital;
use App\Allocation\Application\Event\UserDisassociatedFromHospital;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Application\Activity\UserActivityWrite;
use App\User\Application\Contract\UserActivityRecorderInterface;
use App\User\Domain\Enum\UserActivityType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class HospitalActivitySubscriber
{
    public function __construct(
        private UserActivityRecorderInterface $activityRecorder,
    ) {
    }

    #[AsEventListener(event: UserAssociatedWithHospital::class)]
    public function onAssociated(UserAssociatedWithHospital $event): void
    {
        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::HOSPITAL_ASSOCIATED,
            occurredAt: $event->occurredAt,
            deduplicationKey: UserActivityDeduplicationKey::hospitalAssociated(
                $event->userId,
                $event->hospitalId,
                $event->grantId,
            ),
            metadata: $this->hospitalMetadata($event->hospitalPublicId, $event->hospitalName),
        ));
    }

    #[AsEventListener(event: UserDisassociatedFromHospital::class)]
    public function onDisassociated(UserDisassociatedFromHospital $event): void
    {
        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::HOSPITAL_DISASSOCIATED,
            occurredAt: $event->occurredAt,
            deduplicationKey: UserActivityDeduplicationKey::hospitalDisassociated(
                $event->userId,
                $event->hospitalId,
                $event->grantId,
            ),
            metadata: $this->hospitalMetadata($event->hospitalPublicId, $event->hospitalName),
        ));
    }

    #[AsEventListener(event: HospitalOwnershipGranted::class)]
    public function onOwnershipGranted(HospitalOwnershipGranted $event): void
    {
        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::HOSPITAL_OWNER_GRANTED,
            occurredAt: $event->occurredAt,
            deduplicationKey: UserActivityDeduplicationKey::hospitalOwnerGranted($event->userId, $event->hospitalId),
            metadata: $this->hospitalMetadata($event->hospitalPublicId, $event->hospitalName),
        ));
    }

    #[AsEventListener(event: HospitalOwnershipRevoked::class)]
    public function onOwnershipRevoked(HospitalOwnershipRevoked $event): void
    {
        $this->activityRecorder->record(new UserActivityWrite(
            userId: $event->userId,
            type: UserActivityType::HOSPITAL_OWNER_REVOKED,
            occurredAt: $event->occurredAt,
            deduplicationKey: UserActivityDeduplicationKey::hospitalOwnerRevoked($event->userId, $event->hospitalId),
            metadata: $this->hospitalMetadata($event->hospitalPublicId, $event->hospitalName),
        ));
    }

    /**
     * @return array<string, scalar|null>
     */
    private function hospitalMetadata(string $hospitalPublicId, string $hospitalName): array
    {
        return [
            'hospitalPublicId' => $hospitalPublicId,
            'hospitalName' => $hospitalName,
        ];
    }
}
