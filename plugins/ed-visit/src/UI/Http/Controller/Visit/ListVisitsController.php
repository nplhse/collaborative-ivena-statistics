<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\Http\Controller\Visit;

use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Application\Security\EncounterAttribute;
use App\Import\Domain\Entity\Import;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\User\Domain\Entity\User;
use Cois\Plugin\EdVisit\Application\Contract\VisitListReaderInterface;
use Cois\Plugin\EdVisit\Application\VisitList\VisitLinkState;
use Cois\Plugin\EdVisit\Application\VisitList\VisitListFilter;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalRoute;
use Cois\Plugin\EdVisit\Domain\Enum\Disposition;
use Cois\Plugin\EdVisit\Domain\Enum\MtsCategory;
use Cois\Plugin\EdVisit\UI\Review\ReviewContextResolver;
use Cois\Plugin\EdVisit\UI\VisitList\VisitListColumns;
use Cois\Plugin\EdVisit\UI\VisitList\VisitListHospitals;
use Doctrine\ORM\EntityManagerInterface;
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
 * hospital (ADR 028); open proposals are review information and are shown only with REVIEW. `importId` lists the
 * visits of one import of the hospital instead of a period (#1413); an import of another hospital is not found.
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
        private readonly EntityManagerInterface $entityManager,
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
        $import = $this->import($request, $hospitalId);

        $filter = new VisitListFilter(
            $hospitalId,
            $this->contexts->period($request, $hospitalId),
            $this->isGranted(EncounterAttribute::REVIEW, $hospital),
            $this->enums($request, 'route', ArrivalRoute::class),
            VisitLinkState::tryFrom($request->query->getString('link')),
            $this->enums($request, 'category', MtsCategory::class),
            $request->query->getString('diagram') ?: null,
            Disposition::tryFrom($request->query->getString('disposition')),
            $import?->getId(),
        );

        $schema = $this->columns->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $preferences = $this->preferences->resolve($user, $schema, $queryPreferences->visibleColumns, $queryPreferences->columnOrder, $queryPreferences->pageSize);
        $sortBy = 'age' === $request->query->getString('sortBy') ? 'age' : 'anchor';
        $orderBy = 'asc' === $request->query->getString('orderBy') ? 'asc' : 'desc';
        $limit = max(1, min(100, $request->query->has('limit') ? $request->query->getInt('limit') : $preferences->pageSize));

        return $this->render('@EdVisit/visit_list/list.html.twig', [
            'hospital' => $hospital,
            'hospitals' => $this->hospitals->forUser($user),
            'filter' => $filter,
            'import' => $import,
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

    private function import(Request $request, int $hospitalId): ?Import
    {
        if (!$request->query->has('importId')) {
            return null;
        }

        $import = $this->entityManager->find(Import::class, $request->query->getInt('importId'));
        if (!$import instanceof Import || $import->getHospital()?->getId() !== $hospitalId) {
            throw $this->createNotFoundException('Import not found for this hospital.');
        }

        return $import;
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
