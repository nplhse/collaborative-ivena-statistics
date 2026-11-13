<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Catalog\MtsCatalog;
use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Plausibility\PlausibilityCuration;
use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PzcCodeList;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\UI\Form\PlausibilityAreaType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Creates or edits a curated problem area (ADR 033). Saving rebuilds the seed draft snapshot; the active snapshot
 * never changes.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class EditPlausibilityAreaController extends AbstractController
{
    public function __construct(
        private readonly PlausibilityRepositoryInterface $repository,
        private readonly PlausibilityCuration $curation,
        private readonly MtsCatalog $catalog,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/encounter/plausibility/areas/new', name: 'app_encounter_plausibility_area_new', methods: ['GET', 'POST'])]
    #[Route('/encounter/plausibility/areas/{code}/edit', name: 'app_encounter_plausibility_area_edit', requirements: ['code' => '[a-z0-9_]+'], methods: ['GET', 'POST'])]
    public function __invoke(Request $request, ?string $code = null): Response
    {
        $area = null;
        if (null !== $code) {
            $area = $this->repository->findAreaByCode($code) ?? throw new NotFoundHttpException();
        }

        $form = $this->createForm(PlausibilityAreaType::class, [
            'germanName' => $area?->getGermanName(),
            'englishName' => $area?->getEnglishName(),
            'pzcCodes' => $area instanceof PlausibilityProblemArea ? PzcCodeList::format($area->pzcCodes()) : '',
            'diagrams' => $area?->diagramPoints() ?? [],
        ], ['diagrams' => $this->catalog->diagrams()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{germanName: string, englishName: ?string, pzcCodes: ?string, diagrams: array<string, ?int>} $data */
            $data = $form->getData();
            $diagrams = array_filter($data['diagrams'], static fn (?int $points): bool => null !== $points);
            try {
                $pzcCodes = PzcCodeList::parse((string) $data['pzcCodes']);
                if ($area instanceof PlausibilityProblemArea) {
                    $this->curation->updateArea($area, $data['germanName'], $data['englishName'], $pzcCodes, $diagrams);
                } else {
                    $area = $this->curation->createArea($data['germanName'], $data['englishName'], $pzcCodes, $diagrams);
                }

                $this->addFlash('success', new TranslatableMessage('encounter.plausibility.flash.area_saved', ['name' => $area->getGermanName()], 'encounter'));

                return $this->redirectToRoute('app_encounter_plausibility');
            } catch (PlausibilityException $exception) {
                $form->addError(new FormError($this->translator->trans($exception->translationKey(), $exception->parameters(), 'encounter')));
            }
        }

        return $this->render('@Encounter/plausibility/area_form.html.twig', [
            'area' => $area,
            'form' => $form,
        ], new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
