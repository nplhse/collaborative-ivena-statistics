<?php

namespace App\Controller\Data\Specialities;

use App\DataTransferObjects\SpecialityQueryParametersDTO;
use App\Repository\DepartmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/department', name: 'app_data_department_list')]
final class ListDepartmentController extends AbstractController
{
    public function __construct(
        private readonly DepartmentRepository $departmentRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] SpecialityQueryParametersDTO $query,
    ): Response {
        $paginator = $this->departmentRepository->getListPaginator($query);

        return $this->render('data/departments/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_department_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
