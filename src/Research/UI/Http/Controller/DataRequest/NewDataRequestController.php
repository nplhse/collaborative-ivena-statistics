<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestDraft;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\UI\Form\DataRequestType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * A participant starts a data extract request (ADR 045). Saving keeps it as a draft that only the applicant sees;
 * submitting is a separate step on the detail page.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class NewDataRequestController extends AbstractController
{
    public function __construct(
        private readonly DataRequestService $service,
    ) {
    }

    #[Route('/requests/data/new', name: 'app_data_requests_new', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        $draft = new DataRequestDraft();
        $form = $this->createForm(DataRequestType::class, $draft);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $dataRequest = $this->service->createDraft($user, $draft);
            $this->addFlash('success', new TranslatableMessage('data_request.flash.drafted', domain: 'research'));

            return $this->redirectToRoute('app_data_requests_show', ['publicId' => $dataRequest->getPublicIdString()]);
        }

        return $this->render('@Research/data_request/form.html.twig', [
            'form' => $form,
            'dataRequest' => null,
        ]);
    }
}
