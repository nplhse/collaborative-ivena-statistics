<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Plausibility;

use App\Encounter\Application\Plausibility\PlausibilityException;
use App\Encounter\Application\Plausibility\PlausibilityLearner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Runs the learning job (ADR 033, #1155): a new draft snapshot learned from all hospitals' eligible pairs, audited by
 * PlausibilityLearner. The active snapshot stays; the draft opens with its report.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
#[IsGranted('ROLE_ENCOUNTER_CATALOG_REVIEWER')]
final class LearnPlausibilitySnapshotController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'encounter_plausibility_learn';

    public function __construct(
        private readonly PlausibilityLearner $learner,
    ) {
    }

    #[Route('/tools/encounter-plausibility/learn', name: 'app_tools_encounter_plausibility_learn', methods: ['POST'])]
    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('encounter.plausibility.flash.invalid_token', domain: 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_plausibility');
        }

        try {
            $snapshot = $this->learner->learn();
        } catch (PlausibilityException $exception) {
            $this->addFlash('warning', new TranslatableMessage($exception->translationKey(), $exception->parameters(), 'encounter'));

            return $this->redirectToRoute('app_tools_encounter_plausibility');
        }

        $this->addFlash('success', new TranslatableMessage('encounter.plausibility.flash.snapshot_learned', ['id' => (int) $snapshot->getId()], 'encounter'));

        return $this->redirectToRoute('app_tools_encounter_plausibility_snapshot', ['id' => $snapshot->getId()]);
    }
}
