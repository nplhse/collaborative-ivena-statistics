<?php

namespace App\Allocation\UI\Http\Controller\Allocations;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Query\ListAllocationsQuery;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Allocation\UI\Http\DTO\AllocationQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/allocation', name: 'app_explore_allocation_list')]
final class ListAllocationsController extends AbstractController
{
    public function __construct(
        private readonly ListAllocationsQuery $allocationsQuery,
        private readonly DispatchAreaRepository $dispatchAreaRepository,
        private readonly StateRepository $stateRepository,
        private readonly IndicationNormalizedRepository $normalizedRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] AllocationQueryParametersDTO $query,
    ): Response {
        $paginator = $this->allocationsQuery->getPaginator($query);

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
            'states' => $this->stateRepository->findAll(),
            'dispatchAreas' => $this->dispatchAreaRepository->findAll(),
            'indications' => $this->normalizedRepository->findAll(),
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
            'dispatchArea',
            'state',
            'requiresResus',
            'requiresCathlab',
        ];

        foreach ($filterFields as $field) {
            $value = $queryParametersDTO->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$activeFilterCount;
            }
        }

        return $activeFilterCount;
    }
}
