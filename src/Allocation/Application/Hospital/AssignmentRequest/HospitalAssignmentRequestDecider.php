<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\AssignmentRequest;

use App\Allocation\Application\Event\HospitalAssignmentRequestDecided;
use App\Allocation\Application\Hospital\HospitalRelationNotifier;
use App\Allocation\Application\Hospital\UploadAuthority\UploadAuthority;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\Enum\PreviousOwnerAccess;
use App\Allocation\Domain\Exception\HospitalAssignmentRequestException;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Administrators decide hospital assignment requests (#975).
 *
 * Every decision runs in one transaction: the request and the hospital are locked, the request must still have the
 * version the reviewer saw, and a transfer the owner the reviewer saw. Then the access grant is created, or the
 * ownership is transferred and the previous owner's access handled as chosen, and the decision is recorded. An upload
 * authority the requester confirmed in the request is stored when the approval includes the import permission (#1565).
 * Any
 * failure leaves everything as it was and the request pending. Notifications follow the commit.
 */
final readonly class HospitalAssignmentRequestDecider
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private HospitalAccessGrantRepository $grants,
        private AuditContext $auditContext,
        private HospitalRelationNotifier $relationNotifier,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
        private UploadAuthority $uploadAuthority,
    ) {
    }

    /**
     * Matches a request for a named hospital to an existing one.
     *
     * @throws HospitalAssignmentRequestRefused
     */
    public function resolveHospital(HospitalAssignmentRequest $request, Hospital $hospital, int $expectedVersion): void
    {
        $this->decide('hospital.assignment_request.hospital_resolved', $request, $expectedVersion, static function (HospitalAssignmentRequest $request) use ($hospital): void {
            $request->resolveHospital($hospital);
        });
    }

    /**
     * @throws HospitalAssignmentRequestRefused
     */
    public function reject(HospitalAssignmentRequest $request, User $reviewer, ?string $message, int $expectedVersion): void
    {
        $this->decide('hospital.assignment_request.rejected', $request, $expectedVersion, function (HospitalAssignmentRequest $request) use ($reviewer, $message): void {
            $request->reject($reviewer, $message, $this->clock->now());
        });

        $this->eventDispatcher->dispatch(new HospitalAssignmentRequestDecided((int) $request->getId(), null));
    }

    /**
     * Gives the requester an access grant with $permissions.
     *
     * @throws HospitalAssignmentRequestRefused
     */
    public function approveAsAccessGrant(HospitalAssignmentRequest $request, User $reviewer, int $permissions, ?string $message, int $expectedVersion): void
    {
        $permissions = HospitalPermissionMask::normalize($permissions);
        if (!HospitalPermissionMask::isValid($permissions) || !HospitalPermissionMask::has($permissions, HospitalPermission::View)) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::INVALID_PERMISSIONS);
        }

        $grant = null;
        $this->decide('hospital.assignment_request.approved', $request, $expectedVersion, function (HospitalAssignmentRequest $request) use ($reviewer, $permissions, $message, &$grant): void {
            $hospital = $this->lockedHospital($request);
            $requester = $request->getRequester();
            if ($hospital->getOwner()?->getId() === $requester->getId() || $this->grants->existsForUserAndHospital($requester, $hospital)) {
                throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::ALREADY_ASSIGNED);
            }

            $grant = new HospitalAccessGrant()
                ->setHospital($hospital)
                ->setUser($requester)
                ->setPermissions($permissions)
                ->setCreatedBy($reviewer);
            $this->entityManager->persist($grant);
            if (HospitalPermissionMask::has($permissions, HospitalPermission::Import)) {
                $this->storeUploadAuthority($request, $hospital);
            }
            $request->approveAsAccessGrant($reviewer, $permissions, $message, $this->clock->now());
        });

        if ($grant instanceof HospitalAccessGrant) {
            $this->relationNotifier->associated($grant);
        }
        $this->eventDispatcher->dispatch(new HospitalAssignmentRequestDecided((int) $request->getId(), null));
    }

    /**
     * Makes the requester owner of the hospital. When the hospital has an owner, it must still be the one with
     * $expectedOwnerId, and $previousOwnerAccess decides whether they keep full access as a grant or lose it.
     *
     * @throws HospitalAssignmentRequestRefused
     */
    public function approveAsOwnership(HospitalAssignmentRequest $request, User $reviewer, ?int $expectedOwnerId, ?PreviousOwnerAccess $previousOwnerAccess, ?string $message, int $expectedVersion): void
    {
        $previousOwner = null;
        $keptGrant = null;
        $hospital = null;
        $this->decide('hospital.assignment_request.approved', $request, $expectedVersion, function (HospitalAssignmentRequest $request) use ($reviewer, $expectedOwnerId, $previousOwnerAccess, $message, &$previousOwner, &$keptGrant, &$hospital): void {
            $hospital = $this->lockedHospital($request);
            $requester = $request->getRequester();
            $previousOwner = $hospital->getOwner();
            if ($previousOwner?->getId() !== $expectedOwnerId) {
                throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::OWNER_CHANGED);
            }

            if ($previousOwner?->getId() === $requester->getId()) {
                throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::ALREADY_ASSIGNED);
            }

            if ($previousOwner instanceof User && !$previousOwnerAccess instanceof PreviousOwnerAccess) {
                throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::PREVIOUS_OWNER_CHOICE_REQUIRED);
            }

            // The owner holds every permission; a grant of the new owner would only duplicate that.
            $requesterGrant = $this->grants->findForUserAndHospital($requester, $hospital);
            if ($requesterGrant instanceof HospitalAccessGrant) {
                $this->entityManager->remove($requesterGrant);
            }

            $hospital->setOwner($requester);
            // An owner holds every permission, the import permission included.
            $this->storeUploadAuthority($request, $hospital);
            if ($previousOwner instanceof User) {
                $keptGrant = $this->applyPreviousOwnerAccess($hospital, $previousOwner, $previousOwnerAccess, $reviewer);
            }

            $request->approveAsOwnership($reviewer, $previousOwner, $previousOwnerAccess, $message, $this->clock->now());
        });

        if ($hospital instanceof Hospital) {
            $this->relationNotifier->ownershipChanged($previousOwner, $request->getRequester(), $hospital);
        }
        if ($keptGrant instanceof HospitalAccessGrant) {
            $this->relationNotifier->associated($keptGrant);
        }
        $this->eventDispatcher->dispatch(new HospitalAssignmentRequestDecided((int) $request->getId(), $previousOwner?->getId()));
    }

    /** The upload authority from the request (#1565), with the time and terms version of the request. */
    private function storeUploadAuthority(HospitalAssignmentRequest $request, Hospital $hospital): void
    {
        $confirmedAt = $request->getUploadAuthorityConfirmedAt();
        if (!$confirmedAt instanceof \DateTimeImmutable) {
            return;
        }

        $requester = $request->getRequester();
        $this->uploadAuthority->confirm($requester, $hospital, $requester->getLocale() ?? 'de', $confirmedAt, $request->getUploadAuthorityTermsVersion());
    }

    /**
     * @return HospitalAccessGrant|null the grant the previous owner keeps, when it is new
     */
    private function applyPreviousOwnerAccess(Hospital $hospital, User $previousOwner, ?PreviousOwnerAccess $access, User $reviewer): ?HospitalAccessGrant
    {
        $grant = $this->grants->findForUserAndHospital($previousOwner, $hospital);
        if (PreviousOwnerAccess::RemoveAccess === $access) {
            if ($grant instanceof HospitalAccessGrant) {
                $this->entityManager->remove($grant);
            }

            return null;
        }

        $all = HospitalPermissionMask::fromPermissions(HospitalPermission::assignableCases());
        if ($grant instanceof HospitalAccessGrant) {
            $grant->setPermissions($all);

            return null;
        }

        $grant = new HospitalAccessGrant()
            ->setHospital($hospital)
            ->setUser($previousOwner)
            ->setPermissions($all)
            ->setCreatedBy($reviewer);
        $this->entityManager->persist($grant);

        return $grant;
    }

    /**
     * Runs one decision in a transaction with the request locked and its version checked.
     *
     * @param callable(HospitalAssignmentRequest): void $step
     *
     * @throws HospitalAssignmentRequestRefused
     */
    private function decide(string $intent, HospitalAssignmentRequest $request, int $expectedVersion, callable $step): void
    {
        $this->auditContext->beginIntent($intent, ['hospital_id' => $request->getHospital()?->getId()]);
        try {
            $this->entityManager->wrapInTransaction(function () use ($request, $expectedVersion, $step): void {
                $this->lockAndRefresh($request);
                if ($request->getVersion() !== $expectedVersion) {
                    throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::CHANGED_MEANWHILE);
                }

                try {
                    $step($request);
                } catch (HospitalAssignmentRequestException $exception) {
                    throw new HospitalAssignmentRequestRefused($request->isPending() ? HospitalAssignmentRequestRefused::HOSPITAL_REQUIRED : HospitalAssignmentRequestRefused::NOT_PENDING, $exception);
                }
            });
        } finally {
            $this->auditContext->endIntent();
        }
    }

    /**
     * @throws HospitalAssignmentRequestRefused
     */
    private function lockedHospital(HospitalAssignmentRequest $request): Hospital
    {
        $hospital = $request->getHospital();
        if (!$hospital instanceof Hospital) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::HOSPITAL_REQUIRED);
        }

        $this->lockAndRefresh($hospital);

        return $hospital;
    }

    /** Locks the row for the rest of the transaction and reads its current state. */
    private function lockAndRefresh(object $entity): void
    {
        $this->entityManager->lock($entity, LockMode::PESSIMISTIC_WRITE);
        $this->entityManager->refresh($entity);
    }
}
