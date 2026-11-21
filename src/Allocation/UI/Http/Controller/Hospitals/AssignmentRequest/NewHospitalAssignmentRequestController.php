<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Hospitals\AssignmentRequest;

use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestRefused;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestService;
use App\Allocation\Application\Hospital\AssignmentRequest\HospitalAssignmentRequestSubmission;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\UI\Form\HospitalAssignmentRequestType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Uid\Uuid;

/**
 * A participant asks to be assigned to a hospital (#975). `?hospital=<public id>` preselects one, as the action on
 * the hospital detail page does.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class NewHospitalAssignmentRequestController extends AbstractController
{
    public function __construct(
        private readonly HospitalAssignmentRequestService $requestService,
        private readonly HospitalRepository $hospitalRepository,
    ) {
    }

    #[Route('/hospitals/requests/new', name: 'app_hospital_assignment_request_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $submission = new HospitalAssignmentRequestSubmission();
        $preselected = $request->query->getString('hospital');
        if (Uuid::isValid($preselected)) {
            $hospital = $this->hospitalRepository->findOneByPublicId($preselected);
            $submission->hospital = $hospital instanceof Hospital ? $hospital : null;
        }

        $choices = $this->hospitalRepository->findAssignmentRequestChoices();
        $form = $this->createForm(HospitalAssignmentRequestType::class, $submission, ['hospital_choices' => $choices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->requestService->submit($user, $submission);
            } catch (HospitalAssignmentRequestRefused $refused) {
                $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'allocation'));

                return $this->redirectToRoute('app_hospital_assignment_request_index');
            }

            $this->addFlash('success', new TranslatableMessage('hospital_assignment_request.flash.submitted', domain: 'allocation'));

            return $this->redirectToRoute('app_hospital_assignment_request_index');
        }

        return $this->render('@Allocation/hospitals/assignment_requests/new.html.twig', [
            'form' => $form,
            'hospitalChoices' => $choices,
            'preselectedHospital' => $submission->hospital,
        ]);
    }
}
