<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\Research\Infrastructure\Security\Voter\DataRequestVoter;
use App\Research\UI\Form\DataRequestType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The applicant changes a version that is not submitted yet (ADR 045). Requests of other users answer 404.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class EditDataRequestController extends AbstractController
{
    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly DataRequestService $service,
    ) {
    }

    #[Route('/requests/data/{publicId}/edit', name: 'app_data_requests_edit', requirements: ['publicId' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, string $publicId): Response
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest || !$dataRequest->isRequestedBy($user)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isGranted(DataRequestVoter::EDIT, $dataRequest)) {
            $this->addFlash('error', new TranslatableMessage(DataRequestRefused::NOT_EDITABLE, domain: 'research'));

            return $this->redirectToRoute('app_data_requests_show', ['publicId' => $publicId]);
        }

        $draft = DataRequestDraft::fromVersion($dataRequest->getCurrentVersion());
        $form = $this->createForm(DataRequestType::class, $draft);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->service->updateDraft($user, $dataRequest, $draft);
                $this->addFlash('success', new TranslatableMessage('data_request.flash.updated', domain: 'research'));
            } catch (DataRequestRefused $refused) {
                $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'research'));
            }

            return $this->redirectToRoute('app_data_requests_show', ['publicId' => $publicId]);
        }

        return $this->render('@Research/data_request/form.html.twig', [
            'form' => $form,
            'dataRequest' => $dataRequest,
        ]);
    }
}
