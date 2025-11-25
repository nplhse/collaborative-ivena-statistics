<?php

namespace App\Allocation\UI\Http\Controller\Occasions;

use App\Allocation\Infrastructure\Repository\OccasionRepository;
use App\Allocation\UI\Http\DTO\OccasionQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/occasion', name: 'app_explore_occasion_list')]
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

        return $this->render('@Allocation/occasions/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_occasion_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
