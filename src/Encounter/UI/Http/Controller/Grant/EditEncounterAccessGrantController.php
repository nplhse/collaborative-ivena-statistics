<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Grant;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\EncounterAccessGrantRepositoryInterface;
use App\Encounter\Application\Grant\EncounterGrantInput;
use App\Encounter\Application\Grant\EncounterGrantManager;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Entity\EncounterAccessGrant;
use App\Encounter\UI\Form\EncounterAccessGrantType;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class EditEncounterAccessGrantController extends AbstractController
{
    public function __construct(
        private readonly EncounterAccessGrantRepositoryInterface $grantRepository,
        private readonly EncounterGrantManager $grantManager,
    ) {
    }

    #[Route('/hospitals/{id}/edit/encounter-access/{grantId}', name: 'app_encounter_grants_edit', requirements: ['id' => '\d+', 'grantId' => '\d+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $grantId): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::MANAGE_GRANTS, $hospital);

        $grant = $this->grantRepository->findOneForHospital($hospital, $grantId);
        if (!$grant instanceof EncounterAccessGrant) {
            throw $this->createNotFoundException('Encounter access grant not found.');
        }

        $input = EncounterGrantInput::fromGrant($grant);
        $form = $this->createForm(EncounterAccessGrantType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $actor = $this->getUser();
            $this->grantManager->change($grant, $input->actions, $actor instanceof User ? $actor : null);
            $this->addFlash('success', new TranslatableMessage('encounter.grant.flash.updated', domain: 'encounter'));

            return $this->redirectToRoute('app_encounter_grants', ['id' => $hospital->getId()]);
        }

        return $this->render('@Encounter/grants/edit.html.twig', [
            'hospital' => $hospital,
            'grant' => $grant,
            'form' => $form,
        ]);
    }
}
