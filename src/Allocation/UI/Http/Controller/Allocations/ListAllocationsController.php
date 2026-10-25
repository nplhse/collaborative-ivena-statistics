<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Allocations;

use App\Allocation\Application\Allocations\AllocationListHospitalScopeOptionsProvider;
use App\Allocation\Application\Explore\ExploreFilterOptionsProvider;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use App\Allocation\UI\Twig\AllocationListColumns;
use App\Import\Application\Service\ImportListAccess;
use App\Shared\Application\DataTable\DataTablePreferenceService;
use App\Shared\UI\Http\DataTablePreferenceQueryState;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/allocation', name: 'app_explore_allocation_list', methods: ['GET'])]
final class ListAllocationsController extends AbstractController
{
    public function __construct(
        private readonly ListAllocationsQuery $allocationsQuery,
        private readonly ExploreFilterOptionsProvider $filterOptionsProvider,
        private readonly AllocationListHospitalScopeOptionsProvider $hospitalScopeOptionsProvider,
        private readonly ImportListAccess $importListAccess,
        private readonly AllocationListColumns $columns,
        private readonly DataTablePreferenceService $dataTablePreferences,
    ) {
    }

    public function __invoke(
        Request $request,
        #[MapQueryString] AllocationQueryParametersDTO $query,
    ): Response {
        $user = $this->getUser();
        $participant = $user instanceof User ? $user : null;

        $schema = $this->columns->preferenceSchema();
        $queryPreferences = DataTablePreferenceQueryState::fromRequest($request);
        $preferences = $this->dataTablePreferences->resolve(
            $participant,
            $schema,
            $queryPreferences->visibleColumns,
            $queryPreferences->columnOrder,
            $queryPreferences->pageSize,
        );

        $paginator = $this->allocationsQuery->getPaginator(
            $query,
            $participant,
            array_values(array_intersect(AllocationListColumns::JOINED_COLUMNS, $preferences->visibleColumns)),
            // An explicit limit wins (shared links); otherwise the user's saved page size applies.
            $request->query->has('limit') ? null : $preferences->pageSize,
        );
        $hospitalScopeOptions = null;
        if ($participant instanceof User && $this->hospitalScopeOptionsProvider->canUseFilter($participant)) {
            $hospitalScopeOptions = $this->hospitalScopeOptionsProvider->optionsFor($participant);
        }

        $filterOptions = $this->filterOptionsProvider->allocationListOptions();
        $filterOptions['secondaryIndications'] = $this->filterOptionsProvider->secondaryIndicationsIncluding(
            $query->secondaryIndication,
        );

        return $this->render('@Allocation/allocations/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_allocation_list',
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'allocationColumns' => AllocationListColumns::columns(),
            'allocationPreferences' => $preferences,
            'allocationPreferenceKey' => $schema->key,
            'allocationPreferencesPersisted' => $participant instanceof User,
            'filters' => $query,
            'activeFilterCount' => $this->countActiveFilters($query),
            'tiers' => HospitalTier::cases(),
            'locations' => HospitalLocation::cases(),
            'sizes' => HospitalSize::cases(),
            'urgencies' => AllocationUrgency::cases(),
            ...$filterOptions,
            'transportTypes' => AllocationTransportType::cases(),
            'hospitalScopeOptions' => $hospitalScopeOptions,
            'canImport' => $participant instanceof User && [] !== $this->importListAccess->resolveAccessibleHospitalIds($participant),
        ]);
    }

    private function countActiveFilters(AllocationQueryParametersDTO $queryParametersDTO): int
    {
        $activeFilterCount = 0;

        $filterFields = [
            'tier',
            'location',
            'size',
            'urgency',
            'indication',
            'secondaryIndication',
            'secondaryTransport',
            'department',
            'speciality',
            'assignment',
            'occasion',
            'departmentWasClosed',
            'transportType',
            'dispatchArea',
            'state',
            'requiresResus',
            'requiresCathlab',
            'isVentilated',
            'isShock',
            'isCPR',
            'isPregnant',
            'isWorkAccident',
            'isInfectious',
            'infection',
        ];

        foreach ($filterFields as $field) {
            $value = $queryParametersDTO->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$activeFilterCount;
            }
        }

        if ($queryParametersDTO->hasCreatedAtRange()) {
            ++$activeFilterCount;
        }

        if ('' !== $queryParametersDTO->resolvedHospitalFilter()) {
            ++$activeFilterCount;
        }

        return $activeFilterCount;
    }
}
