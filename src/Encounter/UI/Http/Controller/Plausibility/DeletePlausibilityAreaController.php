<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Plausibility\PlausibilityCuration;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Deletes a curated problem area (ADR 033); its codes then belong to no area. Rebuilds the seed draft snapshot.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class DeletePlausibilityAreaController extends AbstractController
{
    public function __construct(
        private readonly PlausibilityRepositoryInterface $repository,
        private readonly PlausibilityCuration $curation,
    ) {
    }

    #[Route('/tools/encounter-plausibility/areas/{code}/delete', name: 'app_tools_encounter_plausibility_area_delete', requirements: ['code' => '[a-z0-9_]+'], methods: ['POST'])]
    public function __invoke(Request $request, string $code): RedirectResponse
    {
        $area = $this->repository->findAreaByCode($code) ?? throw new NotFoundHttpException();
        if (!$this->isCsrfTokenValid('encounter_plausibility_area_delete_'.$code, $request->request->getString('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('encounter.plausibility.flash.invalid_token', domain: 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_plausibility_area_edit', ['code' => $code]);
        }

        $name = $area->getGermanName();
        $this->curation->deleteArea($area);
        $this->addFlash('success', new TranslatableMessage('encounter.plausibility.flash.area_deleted', ['name' => $name], 'encounter'));

        return $this->redirectToRoute('app_tools_encounter_plausibility');
    }
}
