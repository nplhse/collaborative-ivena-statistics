<?php

namespace App\Allocation\UI\Http\Controller\Specialities;

use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\UI\Http\DTO\SpecialityQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/department', name: 'app_explore_department_list')]
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

        return $this->render('@Allocation/departments/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_department_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
