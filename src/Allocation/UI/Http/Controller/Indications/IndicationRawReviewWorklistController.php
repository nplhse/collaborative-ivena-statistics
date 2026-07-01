<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Domain\Enum\IndicationRawReviewWorklistSegment;
use App\Allocation\Infrastructure\Query\IndicationRawOccurrenceQuery;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\Allocation\UI\Http\DTO\IndicationRawReviewWorklistQueryDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted(IndicationRawReviewVoter::VIEW)]
final class IndicationRawReviewWorklistController extends AbstractController
{
    public function __construct(
        private readonly IndicationRawRepository $rawRepository,
        private readonly IndicationRawOccurrenceQuery $occurrenceQuery,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/explore/indication/raw/review', name: 'app_explore_indication_raw_review_worklist', methods: ['GET'])]
    public function __invoke(
        #[MapQueryString] IndicationRawReviewWorklistQueryDTO $query,
    ): Response {
        $query = $this->normalizeQuery($query);
        $paginator = $this->rawRepository->getReviewWorklistPaginator($query);
        $results = iterator_to_array($paginator->getResults());
        $numResults = $paginator->getNumResults();

        $rawIds = array_values(array_map(static fn (object $raw): int => (int) $raw->getId(), $results));
        $occurrenceCounts = $this->occurrenceQuery->fetchCountsForIds($rawIds);

        $listQueryParams = $this->listQueryParams($query);

        $segmentTabs = [];
        foreach (IndicationRawReviewWorklistSegment::tabOrder() as $segment) {
            $segmentTabs[] = [
                'name' => sprintf(
                    '%s (%d)',
                    $this->translator->trans('indication.review.segment.'.$segment->value, [], 'allocation'),
                    $this->occurrenceQuery->countBySegment($segment->value),
                ),
                'path' => $this->generateUrl('app_explore_indication_raw_review_worklist', [
                    ...$listQueryParams,
                    'segment' => $segment->value,
                ]),
                'active' => $segment === $query->segment,
            ];
        }

        return $this->render('@Allocation/indications/review_worklist.html.twig', [
            'paginator' => $paginator,
            'results' => $results,
            'num_results' => $numResults,
            'occurrence_counts' => $occurrenceCounts,
            'query' => $query,
            'segment_tabs' => $segmentTabs,
            'can_edit_match' => $this->isGranted(IndicationRawReviewVoter::EDIT_MATCH),
            'can_review' => $this->isGranted(IndicationRawReviewVoter::REVIEW),
        ]);
    }

    private function normalizeQuery(IndicationRawReviewWorklistQueryDTO $query): IndicationRawReviewWorklistQueryDTO
    {
        $segment = $query->segment->forWorklist();
        if ($segment === $query->segment) {
            return $query;
        }

        return new IndicationRawReviewWorklistQueryDTO(
            page: $query->page,
            limit: $query->limit,
            orderBy: $query->orderBy,
            sortBy: $query->sortBy,
            search: $query->search,
            segment: $segment,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function listQueryParams(IndicationRawReviewWorklistQueryDTO $query): array
    {
        return array_filter([
            'search' => $query->search,
            'sortBy' => 'createdAt' !== $query->sortBy ? $query->sortBy : null,
            'orderBy' => 'asc' !== $query->orderBy ? $query->orderBy : null,
        ], static fn (mixed $value): bool => null !== $value && '' !== $value);
    }
}
