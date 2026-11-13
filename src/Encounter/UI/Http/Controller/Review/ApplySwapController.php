<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchDeciderInterface;
use App\Encounter\Application\Review\MatchDecisionException;
use App\Encounter\Application\Review\MatchReviewNavigator;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\Review\ReviewContextResolver;
use App\Encounter\UI\Review\ReviewNextUrl;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Applies a targeted swap proposal of the allocation (#1054); only a person does. Continues with the next
 * allocation of the segment like every other decision.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ApplySwapController extends AbstractController
{
    public function __construct(
        private readonly MatchDeciderInterface $decider,
        private readonly ReviewContextResolver $contexts,
        private readonly MatchReviewNavigator $navigator,
        private readonly ReviewNextUrl $nextUrl,
    ) {
    }

    #[Route('/tools/encounter-review/{id}/allocations/{allocationId}/swap', name: 'app_tools_encounter_review_swap', requirements: ['id' => '\d+', 'allocationId' => '\d+'], methods: ['POST'])]
    public function __invoke(Request $request, Hospital $hospital, int $allocationId, #[CurrentUser] User $user): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        if (!$this->isCsrfTokenValid('encounter_review_swap', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $hospitalId = (int) $hospital->getId();
        $context = $this->contexts->resolve($request, $hospitalId);
        $position = $this->navigator->position($hospitalId, $context, $allocationId);

        try {
            $this->decider->swap($hospitalId, $allocationId, $request->request->getString('swap'), $user);
        } catch (MatchDecisionException $exception) {
            if (MatchDecisionException::NOT_FOUND === $exception->reason) {
                throw $this->createNotFoundException('Allocation not found in this hospital.', $exception);
            }

            $this->addFlash('warning', new TranslatableMessage($exception->translationKey(), domain: 'encounter'));

            return $this->redirect($this->nextUrl->allocation($hospitalId, $context, $allocationId));
        }

        $this->addFlash('success', new TranslatableMessage('encounter.review.flash.swapped', domain: 'encounter'));
        $next = $this->nextUrl->after($hospitalId, $context, $allocationId, $position);
        if ($next['done']) {
            $this->addFlash('info', new TranslatableMessage('encounter.review.flash.segment_done', domain: 'encounter'));
        }

        return $this->redirect($next['url']);
    }
}
