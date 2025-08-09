<?php

namespace App\Controller\Data\Area;

use App\DataTransferObjects\AreaListQueryParametersDTO;
use App\Repository\DispatchAreaRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

final class ListAreaController extends AbstractController
{
    public function __construct(
        private readonly DispatchAreaRepository $dispatchAreaRepository,
    ) {
    }

    #[Route('/data/area', name: 'app_data_area_list')]
    public function index(
        #[MapQueryString] AreaListQueryParametersDTO $query,
    ): Response {
        $paginator = $this->dispatchAreaRepository->getAreaListPaginator($query);

        return $this->render('data/area/list.html.twig', [
            'paginator' => $paginator,
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
