<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\AssignmentRequest;

use App\Allocation\Application\Event\HospitalAssignmentRequested;
use App\Allocation\Application\Service\HospitalPermissionAccess;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\Exception\HospitalAssignmentRequestException;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Submitting and withdrawing hospital assignment requests by participants (#975). Who may submit is checked by the
 * route (`ROLE_PARTICIPANT`); this service refuses requests for hospitals the participant can already see, a second
 * pending request for the same hospital, and steps on requests of other users or on decided requests.
 */
final readonly class HospitalAssignmentRequestService
{
    public function __construct(
        private HospitalAssignmentRequestRepository $requests,
        private HospitalPermissionAccess $permissionAccess,
        private EntityManagerInterface $entityManager,
        private AuditContext $auditContext,
        private EventDispatcherInterface $eventDispatcher,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws HospitalAssignmentRequestRefused
     */
    public function submit(User $requester, HospitalAssignmentRequestSubmission $submission): void
    {
        $hospital = $submission->hospital;
        if ($hospital instanceof Hospital) {
            $this->assertNotAssigned($requester, $hospital);
        }

        if (!$submission->reason instanceof HospitalAssignmentReason) {
            throw new \InvalidArgumentException('A validated submission has a reason.');
        }

        try {
            $request = new HospitalAssignmentRequest(
                $requester,
                $hospital,
                $submission->hospitalName,
                $submission->reason,
                $submission->explanation,
                $submission->comment,
                $this->clock->now(),
            );
        } catch (HospitalAssignmentRequestException $exception) {
            throw new \InvalidArgumentException('A validated submission is a valid request.', 0, $exception);
        }

        $this->entityManager->persist($request);
        $this->auditContext->beginIntent('hospital.assignment_request.submitted', ['hospital_id' => $hospital?->getId()]);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::ALREADY_PENDING, $exception);
        } finally {
            $this->auditContext->endIntent();
        }

        $this->eventDispatcher->dispatch(new HospitalAssignmentRequested((int) $request->getId(), $request->getPublicIdString()));
    }

    /**
     * @throws HospitalAssignmentRequestRefused
     */
    public function withdraw(User $requester, HospitalAssignmentRequest $request): void
    {
        if ($request->getRequester()->getId() !== $requester->getId()) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::NOT_OWN);
        }

        try {
            $request->withdraw($this->clock->now());
        } catch (HospitalAssignmentRequestException $exception) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::NOT_PENDING, $exception);
        }

        $this->auditContext->beginIntent('hospital.assignment_request.withdrawn', ['hospital_id' => $request->getHospital()?->getId()]);
        try {
            $this->entityManager->flush();
        } finally {
            $this->auditContext->endIntent();
        }
    }

    /**
     * @throws HospitalAssignmentRequestRefused
     */
    private function assertNotAssigned(User $requester, Hospital $hospital): void
    {
        $hospitalId = $hospital->getId();
        if (null !== $hospitalId && $this->permissionAccess->hasPermission($requester, $hospitalId, HospitalPermission::View)) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::ALREADY_ASSIGNED);
        }

        if ($this->requests->hasPendingForRequesterAndHospital($requester, $hospital)) {
            throw new HospitalAssignmentRequestRefused(HospitalAssignmentRequestRefused::ALREADY_PENDING);
        }
    }
}
