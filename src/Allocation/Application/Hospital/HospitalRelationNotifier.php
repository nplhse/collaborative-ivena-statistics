<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital;

use App\Allocation\Application\Event\HospitalOwnershipGranted;
use App\Allocation\Application\Event\HospitalOwnershipRevoked;
use App\Allocation\Application\Event\UserAssociatedWithHospital;
use App\Allocation\Application\Event\UserDisassociatedFromHospital;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\User\Domain\Entity\User;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class HospitalRelationNotifier
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function snapshot(HospitalAccessGrant $grant): ?HospitalAssociationSnapshot
    {
        $userId = $grant->getUser()?->getId();
        $hospital = $grant->getHospital();
        $hospitalId = $hospital?->getId();
        $grantId = $grant->getId();
        $hospitalName = $hospital?->getName();
        $hospitalPublicId = $hospital?->getPublicIdString();
        if (!\is_int($userId) || !\is_int($hospitalId) || !\is_int($grantId)) {
            return null;
        }

        if (!\is_string($hospitalName) || '' === $hospitalName || !\is_string($hospitalPublicId) || '' === $hospitalPublicId) {
            return null;
        }

        return new HospitalAssociationSnapshot(
            userId: $userId,
            hospitalId: $hospitalId,
            grantId: $grantId,
            hospitalPublicId: $hospitalPublicId,
            hospitalName: $hospitalName,
            grantedAt: $grant->getCreatedAt(),
        );
    }

    public function associated(HospitalAccessGrant $grant): void
    {
        $snapshot = $this->snapshot($grant);
        if (!$snapshot instanceof HospitalAssociationSnapshot) {
            return;
        }

        $this->eventDispatcher->dispatch(new UserAssociatedWithHospital(
            userId: $snapshot->userId,
            hospitalId: $snapshot->hospitalId,
            grantId: $snapshot->grantId,
            hospitalPublicId: $snapshot->hospitalPublicId,
            hospitalName: $snapshot->hospitalName,
            occurredAt: $snapshot->grantedAt,
        ));
    }

    public function disassociated(HospitalAssociationSnapshot $snapshot, ?\DateTimeImmutable $occurredAt = null): void
    {
        $this->eventDispatcher->dispatch(new UserDisassociatedFromHospital(
            userId: $snapshot->userId,
            hospitalId: $snapshot->hospitalId,
            grantId: $snapshot->grantId,
            hospitalPublicId: $snapshot->hospitalPublicId,
            hospitalName: $snapshot->hospitalName,
            occurredAt: $occurredAt ?? new \DateTimeImmutable(),
        ));
    }

    public function ownershipChanged(?User $previousOwner, ?User $currentOwner, Hospital $hospital): void
    {
        $previousId = $previousOwner?->getId();
        $currentId = $currentOwner?->getId();
        if ($previousId === $currentId) {
            return;
        }

        $hospitalId = $hospital->getId();
        $hospitalName = $hospital->getName();
        $hospitalPublicId = $hospital->getPublicIdString();
        if (null === $hospitalId || null === $hospitalName || '' === $hospitalName || '' === $hospitalPublicId) {
            return;
        }

        $occurredAt = new \DateTimeImmutable();

        if (null !== $previousId) {
            $this->eventDispatcher->dispatch(new HospitalOwnershipRevoked(
                userId: $previousId,
                hospitalId: $hospitalId,
                hospitalPublicId: $hospitalPublicId,
                hospitalName: $hospitalName,
                occurredAt: $occurredAt,
            ));
        }

        if (null !== $currentId) {
            $this->eventDispatcher->dispatch(new HospitalOwnershipGranted(
                userId: $currentId,
                hospitalId: $hospitalId,
                hospitalPublicId: $hospitalPublicId,
                hospitalName: $hospitalName,
                occurredAt: $occurredAt,
            ));
        }
    }
}
