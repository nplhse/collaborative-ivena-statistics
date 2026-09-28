<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Indications;

use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/indication_group', name: 'app_explore_indication_group_list', methods: ['GET'])]
final class ListIndicationGroupController extends AbstractController
{
    public function __construct(
        private readonly IndicationGroupRepository $indicationGroupRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] SpecialityQueryParametersDTO $query,
    ): Response {
        $paginator = $this->paginator
            ->query($this->indicationGroupRepository->listQuery($query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        return $this->render('@Allocation/indication_groups/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_indication_group_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
