<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Application\DataRequest\DataRequestRefused;
use App\Research\Application\DataRequest\DataRequestService;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The applicant submits or withdraws a data extract request (ADR 045). Requests of other users answer 404.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class TransitionDataRequestController extends AbstractController
{
    public const string CSRF_PREFIX = 'data_request_';

    public function __construct(
        private readonly DataRequestRepository $requests,
        private readonly DataRequestService $service,
    ) {
    }

    #[Route('/requests/data/{publicId}/{transition}', name: 'app_data_requests_transition', requirements: ['publicId' => Requirement::UUID, 'transition' => 'submit|withdraw'], methods: ['POST'])]
    public function __invoke(Request $request, string $publicId, string $transition): RedirectResponse
    {
        $user = $this->getUser();
        $dataRequest = $this->requests->findOneByPublicId($publicId);
        if (!$user instanceof User || !$dataRequest instanceof DataRequest || !$dataRequest->isRequestedBy($user)) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid(self::CSRF_PREFIX.$transition.'_'.$publicId, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            if ('submit' === $transition) {
                $this->service->submit($user, $dataRequest);
            } else {
                $this->service->withdraw($user, $dataRequest);
            }
            $this->addFlash('success', new TranslatableMessage('data_request.flash.'.$transition, domain: 'research'));
        } catch (DataRequestRefused $refused) {
            $this->addFlash('error', new TranslatableMessage($refused->reason, domain: 'research'));
        }

        return $this->redirectToRoute('app_data_requests_show', ['publicId' => $publicId]);
    }
}
