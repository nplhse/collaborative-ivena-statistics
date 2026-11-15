<?php

declare(strict_types=1);

namespace App\Encounter\UI\Http\Controller\Visit;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Contract\VisitListReaderInterface;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Encounter\Application\VisitList\VisitLinkState;
use App\Encounter\Application\VisitList\VisitListFilter;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\Disposition;
use App\Encounter\Domain\Enum\MtsCategory;
use App\Encounter\UI\Review\ReviewContextResolver;
use App\Encounter\UI\VisitList\VisitListColumns;
use App\Encounter\UI\VisitList\VisitListHospitals;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\User\Domain\Entity\User;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\UX\Pagination\PaginatorInterface;

/**
 * All ED visits of one hospital in a period (#1178), with or without IVENA allocation. Needs VIEW_CASES on the
 * hospital (ADR 028); open proposals are review information and are shown only with REVIEW.
 */
#[IsGranted('ROLE_ENCOUNTER_BETA')]
final class ListVisitsController extends AbstractController
{
    public function __construct(
        private readonly VisitListReaderInterface $reader,
        private readonly VisitListHospitals $hospitals,
        private readonly ReviewContextResolver $contexts,
        private readonly VisitListColumns $columns,
        private readonly DataTablePreferenceService $preferences,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    #[Route('/explore/ed-visits/{publicId}', name: 'app_explore_ed_visit_list', requirements: ['publicId' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] User $user,
        #[MapEntity(expr: 'repository.findOneByPublicId(publicId)')] Hospital $hospital,
    ): Response {
        $this->denyAccessUnlessGranted(EncounterAttribute::VIEW_CASES, $hospital);
        $hospitalId = (int) $hospital->getId();

        $filter = new VisitListFilter(
            $hospitalId,
            $this->contexts->period($request, $hospitalId),
            $this->isGranted(EncounterAttribute::REVIEW, $hospital),
            $this->enums($request, 'route', ArrivalRoute::class),
            VisitLinkState::tryFrom($request->query->getString('link')),
            $this->enums($request, 'category', MtsCategory::class),
            $request->query->getString('diagram') ?: null,
            Disposition::tryFrom($request->query->getString('disposition')),
        );

        $schema = $this->columns->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $preferences = $this->preferences->resolve($user, $schema, $queryPreferences->visibleColumns, $queryPreferences->columnOrder, $queryPreferences->pageSize);
        $sortBy = 'age' === $request->query->getString('sortBy') ? 'age' : 'anchor';
        $orderBy = 'asc' === $request->query->getString('orderBy') ? 'asc' : 'desc';
        $limit = max(1, min(100, $request->query->has('limit') ? $request->query->getInt('limit') : $preferences->pageSize));

        return $this->render('@Encounter/visit_list/list.html.twig', [
            'hospital' => $hospital,
            'hospitals' => $this->hospitals->forUser($user),
            'filter' => $filter,
            'period' => $filter->period,
            'routes' => ArrivalRoute::cases(),
            'categories' => MtsCategory::cases(),
            'dispositions' => Disposition::cases(),
            'link_states' => $filter->includeProposals ? VisitLinkState::cases() : [VisitLinkState::Linked, VisitLinkState::Unlinked],
            'diagram_codes' => $this->reader->diagramCodes($hospitalId),
            'sortBy' => $sortBy,
            'orderBy' => $orderBy,
            'columns' => VisitListColumns::columns(),
            'preferences' => $preferences,
            'preference_key' => $schema->key,
            'paginator' => $this->paginator->fromCallbacks(
                fn (int $offset, int $limit): array => $this->reader->visits($filter, $sortBy, $orderBy, $offset, $limit),
                fn (): int => $this->reader->countVisits($filter),
            )->perPage($limit)->paginate(max(1, $request->query->getInt('page', 1))),
        ]);
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    private function enums(Request $request, string $key, string $enum): array
    {
        $values = $request->query->all()[$key] ?? [];
        $cases = [];
        foreach (\is_array($values) ? $values : [$values] as $value) {
            $case = \is_string($value) ? $enum::tryFrom($value) : null;
            if (null !== $case && !\in_array($case, $cases, true)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }
}
