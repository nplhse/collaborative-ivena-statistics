<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Grant;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Grant\EncounterGrantManager;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class DeleteEncounterAccessGrantController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly EncounterGrantManager $grantManager,
    ) {
    }

    #[Route('/hospitals/{id}/edit/encounter-access/{grantId}/delete', name: 'app_encounter_grants_delete', requirements: ['id' => '\d+', 'grantId' => '\d+'], methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request, Hospital $hospital, int $grantId): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::MANAGE_GRANTS, $hospital);

        if (!$this->isCsrfTokenValid('delete_encounter_grant_'.$grantId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $grant = $this->grantRepository->findOneForHospital($hospital, $grantId);
        if (!$grant instanceof EncounterAccessGrant) {
            throw $this->createNotFoundException('Encounter access grant not found.');
        }

        $this->grantManager->revoke($grant);
        $this->addFlash('success', new TranslatableMessage('encounter.grant.flash.deleted', domain: 'encounter'));

        return $this->redirectToRoute('app_encounter_grants', ['id' => $hospital->getId()]);
    }
}
