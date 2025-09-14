<?php

namespace App\Controller\Data\Assignments;

use App\DataTransferObjects\AssignmentQueryParametersDTO;
use App\Repository\AssignmentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/assignment', name: 'app_data_assignment_list')]
final class ListAssignmentController extends AbstractController
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] AssignmentQueryParametersDTO $query,
    ): Response {
        $paginator = $this->assignmentRepository->getListPaginator($query);

        return $this->render('data/assignments/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_assignment_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
