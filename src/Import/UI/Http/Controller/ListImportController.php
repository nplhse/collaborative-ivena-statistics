<?php

namespace App\Import\UI\Http\Controller;

use App\Allocation\UI\Http\DTO\ListImportQueryParametersDTO;
use App\Import\Infrastructure\Repository\ImportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/import', name: 'app_import_index')]
final class ListImportController extends AbstractController
{
    public function __construct(
        private readonly ImportRepository $importRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] ListImportQueryParametersDTO $query,
    ): Response {
        $paginator = $this->importRepository->getPaginator($query);

        return $this->render('@Import/index.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_import_index',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
        ]);
    }
}
