<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Review\MatchReviewNavigator;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\UI\Review\ReviewContextResolver;
use App\Encounter\UI\Review\ReviewNextUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * "Start review" and "leave open" (skip) of the match review (#1054), like the indication review
 * worklist: both walk through the allocations of the current segment and write nothing.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class NavigateMatchReviewController extends AbstractController
{
    public function __construct(
        private readonly ReviewContextResolver $contexts,
        private readonly MatchReviewNavigator $navigator,
        private readonly ReviewNextUrl $nextUrl,
    ) {
    }

    #[Route('/encounter/review/{id}/start', name: 'app_encounter_review_start', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function start(Request $request, Hospital $hospital): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();
        $context = $this->contexts->resolve($request, $hospitalId);

        $first = $this->navigator->first($hospitalId, $context);
        if (null === $first) {
            $this->addFlash('info', new TranslatableMessage('encounter.review.flash.segment_empty', domain: 'encounter'));

            return $this->redirect($this->nextUrl->list($hospitalId, $context));
        }

        return $this->redirect($this->nextUrl->allocation($hospitalId, $context, $first));
    }

    #[Route('/encounter/review/{id}/allocations/{allocationId}/skip', name: 'app_encounter_review_skip', requirements: ['id' => '\d+', 'allocationId' => '\d+'], methods: ['GET'])]
    public function skip(Request $request, Hospital $hospital, int $allocationId): RedirectResponse
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);
        $hospitalId = (int) $hospital->getId();
        $context = $this->contexts->resolve($request, $hospitalId);

        $position = $request->query->has('pos') ? $request->query->getInt('pos') : null;
        $next = $this->nextUrl->after($hospitalId, $context, $allocationId, $position);
        if ($next['done']) {
            $this->addFlash('info', new TranslatableMessage('encounter.review.flash.segment_done', domain: 'encounter'));
        }

        return $this->redirect($next['url']);
    }
}
