<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Infrastructure\Query\IndicationNormalizedListStatsQuery;
use App\Allocation\Infrastructure\Query\IndicationRawOccurrenceQuery;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\UI\Http\DTO\IndicationQueryParametersDTO;
use App\Allocation\UI\Twig\IndicationNormalizedListColumns;
use App\Allocation\UI\Twig\IndicationRawListColumns;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/indication', name: 'app_explore_indication_list', methods: ['GET'])]
final class ListIndicationController extends AbstractController
{
    public function __construct(
        private readonly IndicationNormalizedRepository $indicationRepository,
        private readonly IndicationRawRepository $indicationRawRepository,
        private readonly TranslatorInterface $translator,
        private readonly PaginatorInterface $paginator,
        private readonly IndicationRawListColumns $rawColumns,
        private readonly IndicationNormalizedListColumns $normalizedColumns,
        private readonly DataTablePreferenceService $dataTablePreferences,
        private readonly IndicationRawOccurrenceQuery $rawOccurrenceQuery,
        private readonly IndicationNormalizedListStatsQuery $normalizedStatsQuery,
    ) {
    }

    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[MapQueryString] IndicationQueryParametersDTO $query,
    ): Response {
        $normalized = 'normalized' === $query->type;
        $schema = $normalized ? $this->normalizedColumns->preferenceSchema() : $this->rawColumns->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $preferences = $this->dataTablePreferences->resolve(
            $user,
            $schema,
            $queryPreferences->visibleColumns,
            $queryPreferences->columnOrder,
            $queryPreferences->pageSize,
        );
        // An explicit limit wins (shared links); otherwise the user's saved page size applies.
        $perPage = $request->query->has('limit') ? $query->limit : $preferences->pageSize;

        $source = $normalized
            ? $this->indicationRepository->listQuery($query)
            : $this->indicationRawRepository->listQuery($query);

        $paginator = $this->paginator
            ->query($source)
            ->perPage(max(1, $perPage))
            ->paginate();

        $cellContext = $this->cellContext(
            $normalized,
            $preferences->visibleColumns,
            array_map(static fn (object $row): int => (int) $row->getId(), $paginator->getItems()),
        );

        $tabs = [
            0 => [
                'name' => $this->translator->trans('indication.tab.normalized', [], 'allocation'),
                'path' => $this->generateUrl('app_explore_indication_list', ['type' => 'normalized']),
                'active' => 'normalized' === $query->type,
            ],
            1 => [
                'name' => $this->translator->trans('indication.tab.raw', [], 'allocation'),
                'path' => $this->generateUrl('app_explore_indication_list', ['type' => 'raw']),
                'active' => 'raw' === $query->type,
            ],
        ];

        return $this->render('@Allocation/indications/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_indication_list',
            'tabs' => $tabs,
            'active_type' => $query->type,
            'type' => $query->type,
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'indicationColumns' => $normalized ? IndicationNormalizedListColumns::columns() : IndicationRawListColumns::columns(),
            'indicationPreferences' => $preferences,
            'indicationPreferenceKey' => $schema->key,
            'indicationPreferencesPersisted' => $user instanceof User,
            'cellContext' => $cellContext,
        ]);
    }

    /**
     * Counts and group names for the visible optional columns, read for the current page only.
     *
     * @param list<string> $visibleColumns
     * @param list<int>    $ids
     *
     * @return array<string, mixed>
     */
    private function cellContext(bool $normalized, array $visibleColumns, array $ids): array
    {
        $visible = static fn (string $key): bool => \in_array($key, $visibleColumns, true);
        $context = [];

        if ($visible('occurrence')) {
            $context['occurrence_counts'] = $normalized
                ? $this->normalizedStatsQuery->fetchOccurrenceCounts($ids)
                : $this->rawOccurrenceQuery->fetchCountsForIds($ids);
        }
        if ($normalized && $visible('rawCount')) {
            $context['raw_counts'] = $this->normalizedStatsQuery->fetchRawCounts($ids);
        }
        if ($normalized && $visible('groups')) {
            $context['normalized_groups'] = $this->normalizedStatsQuery->fetchGroupNames($ids);
        }

        return $context;
    }
}
