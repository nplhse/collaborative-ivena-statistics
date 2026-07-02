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
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
    ) {
    }

    public function __invoke(
        #[MapQueryString] AllocationQueryParametersDTO $query,
    ): Response {
        $user = $this->getUser();
        $participant = $user instanceof User ? $user : null;
        $paginator = $this->allocationsQuery->getPaginator($query, $participant);
        $hospitalScopeOptions = null;
        if ($participant instanceof User && $this->hospitalScopeOptionsProvider->canUseFilter($participant)) {
            $hospitalScopeOptions = $this->hospitalScopeOptionsProvider->optionsFor($participant);
        }

        return $this->render('@Allocation/allocations/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_allocation_list',
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'filters' => $query,
            'activeFilterCount' => $this->countActiveFilters($query),
            'tiers' => HospitalTier::cases(),
            'locations' => HospitalLocation::cases(),
            'sizes' => HospitalSize::cases(),
            'urgencies' => AllocationUrgency::cases(),
            ...$this->filterOptionsProvider->allocationListOptions(),
            'transportTypes' => AllocationTransportType::cases(),
            'hospitalScopeOptions' => $hospitalScopeOptions,
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

        if ('' !== $queryParametersDTO->resolvedHospitalFilter()) {
            ++$activeFilterCount;
        }

        return $activeFilterCount;
    }
}
