<?php

namespace App\Allocation\UI\Http\Controller\Specialities;

use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/speciality', name: 'app_explore_speciality_list')]
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

        return $this->render('@Allocation/specialities/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_speciality_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
