<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Security\EncounterAttribute;
use Cois\Plugin\EdVisit\Application\Contract\MatchReviewReaderInterface;
use Cois\Plugin\EdVisit\Application\Contract\MatchSwapFinderInterface;
use Cois\Plugin\EdVisit\UI\Review\ReviewContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Overview of the match review (#1054): links by origin, proposals, conflicts, unlinked allocations and
 * unlinked visits by arrival route, each with its denominator.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowReviewOverviewController extends AbstractController
{
    public function __construct(
        private readonly MatchReviewReaderInterface $review,
        private readonly MatchSwapFinderInterface $swaps,
        private readonly ReviewContextResolver $contexts,
    ) {
    }

    #[Route('/tools/encounter-review/{id}/overview', name: 'app_tools_encounter_review_overview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $hospitalId = (int) $hospital->getId();
        $context = $this->contexts->resolve($request, $hospitalId);

        return $this->render('@EdVisit/review/overview.html.twig', [
            'hospital' => $hospital,
            'context' => $context,
            'summary' => $this->review->summary($hospitalId, $context->period),
            'swap_count' => \count($this->swaps->between($hospitalId, $context->period->from, $context->period->toExclusive())),
        ]);
    }
}
