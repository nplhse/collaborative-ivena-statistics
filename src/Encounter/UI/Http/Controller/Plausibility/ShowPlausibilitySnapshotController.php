<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Contract\PlausibilityRepositoryInterface;
use App\Encounter\Application\Contract\PzcNameReaderInterface;
use App\Encounter\Application\Plausibility\PlausibilityReviewer;
use App\Encounter\Domain\Entity\PlausibilityProblemArea;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Review of one plausibility snapshot before it is activated (ADR 033): per problem area the points and source of its
 * cells and the change against the active snapshot; per PZC × diagram the changed bonus. No counts (Privacy).
 * The learning report and its replay figures follow with the learning job (#1155).
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class ShowPlausibilitySnapshotController extends AbstractController
{
    public function __construct(
        private readonly PlausibilityReviewer $reviewer,
        private readonly PlausibilityRepositoryInterface $repository,
        private readonly PzcNameReaderInterface $pzcNames,
    ) {
    }

    #[Route('/encounter/plausibility/snapshots/{id}', name: 'app_encounter_plausibility_snapshot', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(PlausibilitySnapshot $snapshot): Response
    {
        /** @var array<string, PlausibilityProblemArea> $areaNames */
        $areaNames = [];
        foreach ($this->repository->areas() as $area) {
            $areaNames[$area->getCode()] = $area;
        }

        return $this->render('@Encounter/plausibility/snapshot.html.twig', [
            'review' => $this->reviewer->review($snapshot),
            'areas' => $areaNames,
            'pzc_names' => $this->pzcNames->names(),
        ]);
    }
}
