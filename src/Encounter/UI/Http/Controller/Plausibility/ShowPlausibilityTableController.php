<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Contract\PzcNameReaderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Plausibility table (ADR 033): the curated problem areas and the snapshots, for administrators and catalog reviewers.
 * Shows points, sources and statuses only, never counts (ADR 033, Privacy).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class ShowPlausibilityTableController extends AbstractController
{
    public function __construct(
        private readonly PlausibilityRepositoryInterface $repository,
        private readonly PzcNameReaderInterface $pzcNames,
    ) {
    }

    #[Route('/tools/encounter-plausibility', name: 'app_tools_encounter_plausibility', methods: ['GET'])]
    public function __invoke(): Response
    {
        $areas = $this->repository->areas();
        $names = $this->pzcNames->names();
        $inArea = [];
        foreach ($areas as $area) {
            foreach ($area->pzcCodes() as $pzc) {
                $inArea[$pzc] = true;
            }
        }

        return $this->render('@Encounter/plausibility/index.html.twig', [
            'areas' => $areas,
            'snapshots' => $this->repository->snapshots(),
            'pzc_names' => $names,
            'codes_without_area' => array_diff_key($names, $inArea),
        ]);
    }
}
