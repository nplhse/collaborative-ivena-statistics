<?php

namespace App\Controller\Data\Indications;

use App\DataTransferObjects\IndicationQueryParametersDTO;
use App\Repository\IndicationNormalizedRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/indication', name: 'app_data_indication_list')]
final class ListIndicationController extends AbstractController
{
    public function __construct(
        private readonly IndicationNormalizedRepository $indicationRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] IndicationQueryParametersDTO $query,
    ): Response {
        $paginator = $this->indicationRepository->getListPaginator($query);

        return $this->render('data/indications/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_indication_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
