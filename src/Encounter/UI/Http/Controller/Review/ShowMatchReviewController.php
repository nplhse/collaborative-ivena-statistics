<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Review\OpenReviewItems;
use App\Encounter\Application\Review\ReviewFilter;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\MatchStatus;
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
 * three tabs (open, without proposal, done) and their counts, "start review" in the page header, and the
 * period and the segment's filter in a filter drawer. The counts with denominators live on the overview
 * page, unlinked visits on their own page.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ShowMatchReviewController extends AbstractController
{
    /** Page sizes of the DataTable footer. */
    private const array PAGE_SIZES = [25, 50, 100];

    public function __construct(
        private readonly MatchReviewReaderInterface $review,
        private readonly OpenReviewItems $open,
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

        // The open items are computed once when the tab shows all of them (they include the swap proposals).
        $openItems = ReviewFilter::Open === $context->filter ? $this->open->items($hospitalId, $context) : null;
        $allOpen = null !== $openItems && null === $context->kind ? $openItems : $this->open->items($hospitalId, $context->withFilter(ReviewFilter::Open));
        $counts = [
            ReviewFilter::Open->value => \count($allOpen),
            ReviewFilter::NoProposal->value => $this->review->countAllocationsWithoutProposal($hospitalId, $period),
            ReviewFilter::Done->value => $this->review->countDonePairs($hospitalId, $context->withFilter(ReviewFilter::Done)),
        ];

        $tabs = array_map(fn (ReviewFilter $filter): array => [
            'name' => sprintf('%s (%d)', $this->translator->trans($filter->labelKey(), [], 'encounter'), $counts[$filter->value]),
            'path' => $this->urls->list($hospitalId, $context->withFilter($filter)),
            'active' => $filter === $context->filter,
        ], ReviewFilter::cases());

        [$slice, $count] = match ($context->filter) {
            ReviewFilter::Open => [
                static fn (int $offset, int $limit): array => \array_slice($openItems ?? [], $offset, $limit),
                static fn (): int => \count($openItems ?? []),
            ],
            ReviewFilter::NoProposal => [
                fn (int $offset, int $limit): array => $this->review->allocationsWithoutProposal($hospitalId, $period, $offset, $limit),
                fn (): int => $this->review->countAllocationsWithoutProposal($hospitalId, $period),
            ],
            ReviewFilter::Done => [
                fn (int $offset, int $limit): array => $this->review->donePairs($hospitalId, $context, $offset, $limit),
                fn (): int => $this->review->countDonePairs($hospitalId, $context),
            ],
        };

        return $this->render('@Encounter/review/hospital.html.twig', [
            'hospital' => $hospital,
            'context' => $context,
            'tabs' => $tabs,
            'statuses' => [MatchStatus::AutoAccepted, MatchStatus::Confirmed, MatchStatus::Rejected],
            'paginator' => $this->paginator->fromCallbacks($slice, $count)->perPage($limit)->paginate(max(1, $request->query->getInt('page', 1))),
        ]);
    }
}
