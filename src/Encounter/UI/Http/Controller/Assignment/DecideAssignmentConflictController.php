<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Assignment;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Assignment\AssignmentDecisionOutcome;
use App\Encounter\Application\Assignment\AssignmentState;
use App\Encounter\Application\Contract\AssignmentDeciderInterface;
use App\Encounter\Application\Message\RetryEncounterAssignmentMessage;
use App\Encounter\Application\Security\EncounterAttribute;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * The two manual decisions on an open assignment conflict (ADR 037, #1307): assign the record to one of the
 * candidates, or leave it unassigned for now. The form carries the conflict as the reviewer saw it (kind and
 * candidate ids); when the conflict changed meanwhile nothing is decided and the page shows the new state. If
 * the assignment now finds a unique encounter, the targeted retry is dispatched after the decision's commit.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class DecideAssignmentConflictController extends AbstractController
{
    public function __construct(
        private readonly AssignmentDeciderInterface $decider,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/tools/encounter-assignments/{id}/conflicts/{pendingId}/{action}', name: 'app_tools_encounter_assignments_decide', requirements: ['id' => '\d+', 'pendingId' => '\d+', 'action' => 'assign|defer'], methods: ['POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $pendingId, string $action): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();
        $back = $this->redirectToRoute('app_tools_encounter_assignments_hospital', ['id' => $hospitalId]);

        if (!$this->isCsrfTokenValid(self::tokenId($pendingId), $request->request->getString('_token'))) {
            $this->addFlash('danger', new TranslatableMessage('encounter.assignment.flash.invalid_token', domain: 'encounter'));

            return $back;
        }

        $seen = AssignmentState::fromForm($request->request->getString('kind'), $request->request->getString('candidates'));
        $user = $this->getUser();
        if (!$seen instanceof AssignmentState || !$user instanceof User) {
            $this->addFlash('danger', new TranslatableMessage('encounter.assignment.flash.changed', domain: 'encounter'));

            return $back;
        }

        $outcome = 'assign' === $action
            ? $this->decider->assign($hospitalId, $pendingId, $seen, $request->request->getInt('encounter'), $user)
            : $this->decider->defer($hospitalId, $pendingId, $seen, $user);

        if (AssignmentDecisionOutcome::UniqueCandidate === $outcome) {
            // The decision transaction has committed: the targeted retry attaches the record to its now unique
            // encounter, so that the conflict cannot stay open in this state.
            $this->bus->dispatch(new RetryEncounterAssignmentMessage($hospitalId, $pendingId));
        }

        $this->addFlash(
            AssignmentDecisionOutcome::Decided === $outcome ? 'success' : 'warning',
            new TranslatableMessage(match ($outcome) {
                AssignmentDecisionOutcome::Decided => 'encounter.assignment.flash.'.$action,
                AssignmentDecisionOutcome::UniqueCandidate => 'encounter.assignment.flash.changed',
                default => 'encounter.assignment.flash.'.$outcome->value,
            }, domain: 'encounter'),
        );

        return $back;
    }

    /**
     * CSRF token id of the decision forms of one conflict.
     */
    public static function tokenId(int $pendingId): string
    {
        return 'encounter_assignment_'.$pendingId;
    }
}
