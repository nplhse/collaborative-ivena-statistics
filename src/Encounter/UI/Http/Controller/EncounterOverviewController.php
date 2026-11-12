<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller;

use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Entry page of the encounter data pilot. Lists the user's own encounter grants until the pilot
 * pages of the later steps exist.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class EncounterOverviewController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    #[Route('/encounter', name: 'app_encounter_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $user = $this->getUser();

        return $this->render('@Encounter/index.html.twig', [
            'grants' => $user instanceof User ? $this->grantRepository->findForUser($user) : [],
        ]);
    }
}
