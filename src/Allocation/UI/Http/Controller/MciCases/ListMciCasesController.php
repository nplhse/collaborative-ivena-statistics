<?php

namespace App\Allocation\UI\Http\Controller\MciCases;

use App\Allocation\Infrastructure\Repository\MciCaseRepository;
use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/mci_case', name: 'app_explore_mci_case_list')]
final class ListMciCasesController extends AbstractController
{
    public function __construct(
        private readonly MciCaseRepository $mciCaseRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] MciCaseQueryParametersDTO $query,
    ): Response {
        $paginator = $this->mciCaseRepository->getListPaginator($query);

        return $this->render('@Allocation/mci_cases/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_mci_case_list',
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'importId' => $query->importId,
        ]);
    }
}
