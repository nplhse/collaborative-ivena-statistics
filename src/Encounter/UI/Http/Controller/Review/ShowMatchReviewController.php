<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Contract\MatchSwapFinderInterface;
use App\Encounter\Application\Review\ReviewContext;
use App\Encounter\Application\Review\ReviewFilter;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\UI\Review\ReviewContextResolver;
use App\Encounter\UI\Review\ReviewNextUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Pagination\PaginatorInterface;

/**
 * Worklist of the match review (#1054), laid out like the indication review worklist: one DataTable with
 * a tab per segment and its count, "start review" in the page header and the period and arrival route in
 * a filter drawer. The counts with denominators live on the overview page.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowMatchReviewController extends AbstractController
{
    /** Page sizes of the DataTable footer. */
    private const array PAGE_SIZES = [25, 50, 100];

    public function __construct(
        private readonly MatchReviewReaderInterface $review,
        private readonly MatchSwapFinderInterface $swaps,
        private readonly ReviewContextResolver $contexts,
        private readonly ReviewNextUrl $urls,
        private readonly PaginatorInterface $paginator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/encounter/review/{id}', name: 'app_encounter_review_hospital', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $hospitalId = (int) $hospital->getId();
        $context = $this->contexts->resolve($request, $hospitalId);
        $period = $context->period;
        $limit = max(1, \in_array($request->query->getInt('limit'), self::PAGE_SIZES, true) ? $request->query->getInt('limit') : self::PAGE_SIZES[0]);
        $swaps = $this->swaps->between($hospitalId, $period->from, $period->toExclusive());

        $counts = [];
        foreach (ReviewFilter::cases() as $filter) {
            $counts[$filter->value] = match ($filter) {
                ReviewFilter::NoProposal => $this->review->countAllocationsWithoutProposal($hospitalId, $period),
                ReviewFilter::UnlinkedVisits => $this->review->countUnlinkedVisits($hospitalId, $period, null),
                ReviewFilter::Swaps => \count($swaps),
                default => $this->review->countPairs($hospitalId, $period, $filter),
            };
        }

        $tabs = array_map(fn (ReviewFilter $filter): array => [
            'name' => sprintf('%s (%d)', $this->translator->trans($filter->labelKey(), [], 'encounter'), $counts[$filter->value]),
            'path' => $this->urls->list($hospitalId, $context->withFilter($filter)),
            'active' => $filter === $context->filter,
        ], ReviewFilter::cases());

        [$slice, $count] = $this->source($hospitalId, $context, $swaps);

        return $this->render('@Encounter/review/hospital.html.twig', [
            'hospital' => $hospital,
            'context' => $context,
            'routes' => ArrivalRoute::cases(),
            'tabs' => $tabs,
            'paginator' => $this->paginator->fromCallbacks($slice, $count)->perPage($limit)->paginate(max(1, $request->query->getInt('page', 1))),
        ]);
    }

    /**
     * @param list<\App\Encounter\Application\Review\SwapProposal> $swaps
     *
     * @return array{0: callable(int, int): list<mixed>, 1: callable(): int}
     */
    private function source(int $hospitalId, ReviewContext $context, array $swaps): array
    {
        $period = $context->period;

        return match ($context->filter) {
            ReviewFilter::NoProposal => [
                fn (int $offset, int $limit): array => $this->review->allocationsWithoutProposal($hospitalId, $period, $offset, $limit),
                fn (): int => $this->review->countAllocationsWithoutProposal($hospitalId, $period),
            ],
            ReviewFilter::UnlinkedVisits => [
                fn (int $offset, int $limit): array => $this->review->unlinkedVisits($hospitalId, $period, $context->route, $offset, $limit),
                fn (): int => $this->review->countUnlinkedVisits($hospitalId, $period, $context->route),
            ],
            ReviewFilter::Swaps => [
                static fn (int $offset, int $limit): array => \array_slice($swaps, $offset, $limit),
                static fn (): int => \count($swaps),
            ],
            default => [
                fn (int $offset, int $limit): array => $this->review->pairs($hospitalId, $context, $offset, $limit),
                fn (): int => $this->review->countPairs($hospitalId, $period, $context->filter),
            ],
        };
    }
}
