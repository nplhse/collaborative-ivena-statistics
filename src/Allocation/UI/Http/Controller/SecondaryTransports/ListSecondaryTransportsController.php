<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\SecondaryTransports;

use App\Allocation\Infrastructure\Repository\SecondaryTransportRepository;
use App\Allocation\UI\Http\DTO\SecondaryTransportQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/secondary_transport', name: 'app_explore_secondary_transport_list', methods: ['GET'])]
final class ListSecondaryTransportsController extends AbstractController
{
    public function __construct(
        private readonly SecondaryTransportRepository $secondaryTransportRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] SecondaryTransportQueryParametersDTO $query,
    ): Response {
        $paginator = $this->paginator
            ->query($this->secondaryTransportRepository->listQuery($query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        return $this->render('@Allocation/secondary_transports/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_secondary_transport_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
