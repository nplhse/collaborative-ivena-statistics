<?php

namespace App\Controller\Data\Infections;

use App\DataTransferObjects\InfectionQueryParametersDTO;
use App\Repository\InfectionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/infection', name: 'app_data_infection_list')]
final class ListInfectionController extends AbstractController
{
    public function __construct(
        private readonly InfectionRepository $infectionRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] InfectionQueryParametersDTO $query,
    ): Response {
        $paginator = $this->infectionRepository->getListPaginator($query);

        return $this->render('data/infections/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_infection_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
