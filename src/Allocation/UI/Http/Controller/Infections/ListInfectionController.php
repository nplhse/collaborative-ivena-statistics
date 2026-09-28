<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Infections;

use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Allocation\UI\Http\DTO\InfectionQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/infection', name: 'app_explore_infection_list', methods: ['GET'])]
final class ListInfectionController extends AbstractController
{
    public function __construct(
        private readonly InfectionRepository $infectionRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] InfectionQueryParametersDTO $query,
    ): Response {
        $paginator = $this->paginator
            ->query($this->infectionRepository->listQuery($query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        return $this->render('@Allocation/infections/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_infection_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
