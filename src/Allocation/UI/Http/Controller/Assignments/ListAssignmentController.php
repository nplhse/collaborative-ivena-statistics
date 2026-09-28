<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\Assignments;

use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\UI\Http\DTO\AssignmentQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\UX\Pagination\PaginatorInterface;

#[Route('/explore/assignment', name: 'app_explore_assignment_list', methods: ['GET'])]
final class ListAssignmentController extends AbstractController
{
    public function __construct(
        private readonly AssignmentRepository $assignmentRepository,
        private readonly PaginatorInterface $paginator,
    ) {
    }

    public function __invoke(
        #[MapQueryString] AssignmentQueryParametersDTO $query,
    ): Response {
        $paginator = $this->paginator
            ->query($this->assignmentRepository->listQuery($query))
            ->perPage($query->limit > 0 ? $query->limit : 1)
            ->paginate();

        return $this->render('@Allocation/assignments/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_assignment_list',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
