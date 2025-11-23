<?php

namespace App\Controller\Data\DispatchAreas;

use App\DataTransferObjects\AreaListQueryParametersDTO;
use App\Repository\DispatchAreaRepository;
use App\Repository\StateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/dispatch_area', name: 'app_data_dispatch_area_list')]
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

        return $this->render('data/dispatch_areas/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_dispatch_area_list',
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
