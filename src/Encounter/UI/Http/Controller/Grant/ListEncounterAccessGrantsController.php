<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Grant;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListEncounterAccessGrantsController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
    ) {
    }

    #[Route('/hospitals/{id}/edit/encounter-access', name: 'app_encounter_grants', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::MANAGE_GRANTS, $hospital);

        return $this->render('@Encounter/grants/index.html.twig', [
            'hospital' => $hospital,
            'grants' => $this->grantRepository->findForHospital($hospital),
        ]);
    }
}
