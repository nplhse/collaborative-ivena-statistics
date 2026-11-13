<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Review;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\MatchReviewReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\UI\Review\ReviewContextResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Pagination\PaginatorInterface;

/**
 * ED visits of the period without link (#1054), optionally by arrival route, reached from the counts on the
 * overview page. Nothing is decided here: a visit with an open proposal links to its allocation's review page.
 * Most of them are self-presenters, for whom no allocation is expected.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListUnlinkedVisitsController extends AbstractController
{
    private const array PAGE_SIZES = [25, 50, 100];

    public function __construct(
        private readonly MatchReviewReaderInterface $review,
        private readonly ReviewContextResolver $contexts,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('/encounter/review/{id}/visits', name: 'app_encounter_review_visits', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function __invoke(Request $request, Hospital $hospital): Response
    {
        $this->denyAccessUnlessGranted(EncounterAttribute::REVIEW, $hospital);

        $hospitalId = (int) $hospital->getId();
        $period = $this->contexts->period($request, $hospitalId);
        $route = ArrivalRoute::tryFrom($request->query->getString('route'));
        $limit = max(1, \in_array($request->query->getInt('limit'), self::PAGE_SIZES, true) ? $request->query->getInt('limit') : self::PAGE_SIZES[0]);

        return $this->render('@Encounter/review/visits.html.twig', [
            'hospital' => $hospital,
            'period' => $period,
            'route' => $route,
            'routes' => ArrivalRoute::cases(),
            'paginator' => $this->paginator->fromCallbacks(
                fn (int $offset, int $limit): array => $this->review->unlinkedVisits($hospitalId, $period, $route, $offset, $limit),
                fn (): int => $this->review->countUnlinkedVisits($hospitalId, $period, $route),
            )->perPage($limit)->paginate(max(1, $request->query->getInt('page', 1))),
        ]);
    }
}
