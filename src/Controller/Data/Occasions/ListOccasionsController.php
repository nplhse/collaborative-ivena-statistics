<?php

namespace App\Controller\Data\Occasions;

use App\DataTransferObjects\OccasionQueryParametersDTO;
use App\Repository\OccasionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/occasion', name: 'app_data_occasion_list')]
final class ListOccasionsController extends AbstractController
{
    public function __construct(
        private readonly OccasionRepository $occasionRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] OccasionQueryParametersDTO $query,
    ): Response {
        $paginator = $this->occasionRepository->getListPaginator($query);

        return $this->render('data/occasions/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_occasion_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
