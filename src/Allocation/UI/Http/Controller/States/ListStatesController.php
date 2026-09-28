<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\States;

use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/state', name: 'app_explore_state_list', methods: ['GET'])]
final class ListStatesController extends AbstractController
{
    public function __construct(
        private readonly StateRepository $stateRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] SpecialityQueryParametersDTO $query,
    ): Response {
        $paginator = $this->paginator
            ->query($this->stateRepository->listQuery($query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        return $this->render('@Allocation/states/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_state_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
