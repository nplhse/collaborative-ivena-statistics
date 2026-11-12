<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Security\EncounterAttribute;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Applies a targeted swap proposal of the allocation (#1054); only a person does.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ApplySwapController extends AbstractController
{
    public function __construct(
        private readonly MatchDeciderInterface $decider,
    ) {
    }

    #[Route('/encounter/review/{id}/allocations/{allocationId}/swap', name: 'app_encounter_review_swap', requirements: ['id' => '\d+', 'allocationId' => '\d+'], methods: ['POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $allocationId, #[CurrentUser] User $user): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        if (!$this->isCsrfTokenValid('encounter_review_'.(int) $hospital->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->decider->swap((int) $hospital->getId(), $allocationId, $request->request->getString('swap'), $user);
            $this->addFlash('success', new TranslatableMessage('encounter.review.flash.swapped', domain: 'encounter'));
        } catch (MatchDecisionException $exception) {
            if (MatchDecisionException::NOT_FOUND === $exception->reason) {
                throw $this->createNotFoundException('Allocation not found in this hospital.', $exception);
            }

            $this->addFlash('warning', new TranslatableMessage($exception->translationKey(), domain: 'encounter'));
        }

        $query = array_filter(
            ['from' => $request->request->getString('from'), 'to' => $request->request->getString('to'), 'filter' => $request->request->getString('filter')],
            static fn (string $value): bool => '' !== $value,
        );

        return $this->redirectToRoute('app_encounter_review_allocation', ['id' => $hospital->getId(), 'allocationId' => $allocationId, ...$query]);
    }
}
