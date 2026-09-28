<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Occasions;

use App\Allocation\Infrastructure\Repository\OccasionRepository;
use App\Allocation\UI\Http\DTO\OccasionQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/occasion', name: 'app_explore_occasion_list', methods: ['GET'])]
final class ListOccasionsController extends AbstractController
{
    public function __construct(
        private readonly OccasionRepository $occasionRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] OccasionQueryParametersDTO $query,
    ): Response {
        $paginator = $this->paginator
            ->query($this->occasionRepository->listQuery($query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        return $this->render('@Allocation/occasions/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_occasion_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
