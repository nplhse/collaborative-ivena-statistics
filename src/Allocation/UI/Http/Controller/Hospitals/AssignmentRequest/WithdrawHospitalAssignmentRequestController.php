<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals\AssignmentRequest;

use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestRefused;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestService;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The requester withdraws a pending hospital assignment request (#975). Requests of other users answer 404.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class WithdrawHospitalAssignmentRequestController extends AbstractController
{
    public const string CSRF_PREFIX = 'withdraw_hospital_assignment_request_';

    public function __construct(
        private readonly HospitalAssignmentRequestRepository $requests,
        private readonly HospitalAssignmentRequestService $requestService,
    ) {
    }

    #[Route('/hospitals/requests/{publicId}/withdraw', name: 'app_hospital_assignment_request_withdraw', requirements: ['publicId' => Requirement::UUID], methods: ['POST'])]
    public function __invoke(Request $request, string $publicId): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $user = $this->getUser();
        $assignmentRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$assignmentRequest instanceof HospitalAssignmentRequest || $assignmentRequest->getRequester()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$publicId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->requestService->withdraw($user, $assignmentRequest);
            $this->addFlash('success', new TranslatableMessage('hospital_assignment_request.flash.withdrawn', domain: 'allocation'));
        } catch (HospitalAssignmentRequestRefused $refused) {
            $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'allocation'));
        }

        return $this->redirectToRoute('app_hospital_assignment_request_index');
    }
}
