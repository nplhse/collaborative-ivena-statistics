<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals\AssignmentRequest;

use App\Allocation\Infrastructure\Repository\HospitalAssignmentRequestRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The hospital assignment requests of the signed-in participant, newest first (#975).
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class ListHospitalAssignmentRequestsController extends AbstractController
{
    public function __construct(
        private readonly HospitalAssignmentRequestRepository $requests,
    ) {
    }

    #[Route('/hospitals/requests', name: 'app_hospital_assignment_request_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        return $this->render('@Allocation/hospitals/assignment_requests/list.html.twig', [
            'requests' => $this->requests->findForRequester($user),
        ]);
    }
}
