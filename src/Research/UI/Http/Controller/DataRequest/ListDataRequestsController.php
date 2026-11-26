<?php

declare(strict_types=1);

namespace App\Research\UI\Http\Controller\DataRequest;

use App\Research\Infrastructure\Repository\DataRequestRepository;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Every data extract request participants may see (ADR 045): submitted requests of everyone and the viewer's drafts.
 */
#[IsGranted('ROLE_PARTICIPANT')]
final class ListDataRequestsController extends AbstractController
{
    public function __construct(
        private readonly DataRequestRepository $requests,
    ) {
    }

    #[Route('/requests/data', name: 'app_data_requests', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Authenticated user required.');
        }

        return $this->render('@Research/data_request/list.html.twig', [
            'requests' => $this->requests->findVisibleTo($user),
        ]);
    }
}
