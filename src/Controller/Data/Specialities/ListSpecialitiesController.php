<?php

namespace App\Controller\Data\Specialities;

use App\DataTransferObjects\SpecialityQueryParametersDTO;
use App\Repository\SpecialityRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/speciality', name: 'app_data_speciality_list')]
final class ListSpecialitiesController extends AbstractController
{
    public function __construct(
        private readonly SpecialityRepository $specialityRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] SpecialityQueryParametersDTO $query,
    ): Response {
        $paginator = $this->specialityRepository->getListPaginator($query);

        return $this->render('data/specialities/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_speciality_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
