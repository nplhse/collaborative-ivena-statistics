<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PlausibilitySnapshots;
use App\Encounter\Domain\Entity\PlausibilitySnapshot;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Activates a draft, retires the active snapshot or deletes a draft (ADR 033), audited by PlausibilitySnapshots.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class ChangePlausibilitySnapshotController extends AbstractController
{
    public function __construct(
        private readonly PlausibilitySnapshots $snapshots,
    ) {
    }

    #[Route('/tools/encounter-plausibility/snapshots/{id}/{action}', name: 'app_tools_encounter_plausibility_snapshot_change', requirements: ['id' => '\d+', 'action' => 'activate|retire|delete'], methods: ['POST'])]
    public function __invoke(Request $request, PlausibilitySnapshot $snapshot, string $action): RedirectResponse
    {
        $id = (int) $snapshot->getId();
        if (!$this->isCsrfTokenValid(\sprintf('encounter_plausibility_snapshot_%s_%d', $action, $id), $request->request->getString('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('encounter.plausibility.flash.invalid_token', domain: 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_plausibility_snapshot', ['id' => $id]);
        }

        try {
            match ($action) {
                'activate' => $this->snapshots->activate($snapshot),
                'retire' => $this->snapshots->retire($snapshot),
                default => $this->snapshots->deleteDraft($snapshot),
            };
        } catch (PlausibilityException $exception) {
            $this->addFlash('danger', new TranslatableMessage($exception->translationKey(), $exception->parameters(), 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_plausibility_snapshot', ['id' => $id]);
        }

        $this->addFlash('success', new TranslatableMessage('encounter.plausibility.flash.snapshot_'.$action, ['id' => $id], 'encounter'));

        return 'delete' === $action
            ? $this->redirectToRoute('app_tools_encounter_plausibility')
            : $this->redirectToRoute('app_tools_encounter_plausibility_snapshot', ['id' => $id]);
    }
}
