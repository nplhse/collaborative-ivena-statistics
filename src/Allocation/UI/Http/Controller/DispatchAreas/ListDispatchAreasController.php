<?php

namespace App\Allocation\UI\Http\Controller\DispatchAreas;

use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Allocation\UI\Http\DTO\AreaListQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/dispatch_area', name: 'app_explore_dispatch_area_list')]
final class ListDispatchAreasController extends AbstractController
{
    public function __construct(
        private readonly DispatchAreaRepository $dispatchAreaRepository,
        private readonly StateRepository $stateRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] AreaListQueryParametersDTO $query,
    ): Response {
        $paginator = $this->dispatchAreaRepository->getAreaListPaginator($query);

        return $this->render('@Allocation/dispatch_areas/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_dispatch_area_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'filters' => $query,
            'activeFilterCount' => $this->countActiveFilters($query),
            'states' => $this->stateRepository->findAll(),
        ]);
    }

    private function countActiveFilters(AreaListQueryParametersDTO $queryParametersDTO): int
    {
        $activeFilterCount = 0;

        $filterFields = [
            'state',
            'search',
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
