<?php

namespace App\Controller\Data\Allocations;

use App\DataTransferObjects\AllocationQueryParametersDTO;
use App\Query\ListAllocationsQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/allocation', name: 'app_data_allocation_list')]
final class ListAllocationsController extends AbstractController
{
    public function __construct(
        private readonly ListAllocationsQuery $allocationsQuery,
    ) {
    }

    public function __invoke(
        #[MapQueryString] AllocationQueryParametersDTO $query,
    ): Response {
        $paginator = $this->allocationsQuery->getPaginator($query);

        dump($paginator);

        return $this->render('data/allocations/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_allocation_list',
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
