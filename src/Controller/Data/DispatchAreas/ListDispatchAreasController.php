<?php

namespace App\Controller\Data\DispatchAreas;

use App\DataTransferObjects\AreaListQueryParametersDTO;
use App\Repository\DispatchAreaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/area', name: 'app_data_dispatch_area_list')]
final class ListDispatchAreasController extends AbstractController
{
    public function __construct(
        private readonly DispatchAreaRepository $dispatchAreaRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] AreaListQueryParametersDTO $query,
    ): Response {
        $paginator = $this->dispatchAreaRepository->getAreaListPaginator($query);

        return $this->render('data/dispatch_areas/list.html.twig', [
            'paginator' => $paginator,
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
