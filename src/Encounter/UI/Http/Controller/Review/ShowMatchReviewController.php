<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\MatchSwapFinderInterface;
use App\Encounter\Application\Review\ReviewFilter;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\UI\Review\ReviewPeriodResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Pagination\PaginatorInterface;

/**
 * Match review of one hospital and period (#1054): counts with denominators and one review list.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowMatchReviewController extends AbstractController
{
    /** Page sizes of the DataTable footer. */
    private const array PAGE_SIZES = [25, 50, 100];

    public function __construct(
        private readonly MatchReviewReaderInterface $review,
        private readonly MatchSwapFinderInterface $swaps,
        private readonly ReviewPeriodResolver $periods,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('/encounter/review/{id}', name: 'app_encounter_review_hospital', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $hospitalId = (int) $hospital->getId();
        $period = $this->periods->resolve($request, $hospitalId);
        $filter = ReviewFilter::tryFrom($request->query->getString('filter')) ?? ReviewFilter::Proposed;
        $route = ArrivalRoute::tryFrom($request->query->getString('route'));
        $limit = max(1, \in_array($request->query->getInt('limit'), self::PAGE_SIZES, true) ? $request->query->getInt('limit') : self::PAGE_SIZES[0]);

        $summary = $this->review->summary($hospitalId, $period);
        $swaps = $this->swaps->between($hospitalId, $period->from, $period->toExclusive());

        [$slice, $count] = match ($filter) {
            ReviewFilter::NoProposal => [
                fn (int $offset, int $limit): array => $this->review->allocationsWithoutProposal($hospitalId, $period, $offset, $limit),
                fn (): int => $this->review->countAllocationsWithoutProposal($hospitalId, $period),
            ],
            ReviewFilter::UnlinkedVisits => [
                fn (int $offset, int $limit): array => $this->review->unlinkedVisits($hospitalId, $period, $route, $offset, $limit),
                fn (): int => $this->review->countUnlinkedVisits($hospitalId, $period, $route),
            ],
            ReviewFilter::Swaps => [
                static fn (int $offset, int $limit): array => \array_slice($swaps, $offset, $limit),
                static fn (): int => \count($swaps),
            ],
            default => [
                fn (int $offset, int $limit): array => $this->review->pairs($hospitalId, $period, $filter, $offset, $limit),
                fn (): int => $this->review->countPairs($hospitalId, $period, $filter),
            ],
        };

        return $this->render('@Encounter/review/hospital.html.twig', [
            'hospital' => $hospital,
            'period' => $period,
            'filter' => $filter,
            'filters' => ReviewFilter::cases(),
            'route' => $route,
            'routes' => ArrivalRoute::cases(),
            'summary' => $summary,
            'swap_count' => \count($swaps),
            'items' => $this->paginator->fromCallbacks($slice, $count)->perPage($limit)->paginate(max(1, $request->query->getInt('page', 1))),
        ]);
    }
}
