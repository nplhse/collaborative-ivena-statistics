<?php

declare(strict_types=1);

namespace App\Admin\UI\Http\Controller\ImportRejects;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Infrastructure\Query\ListImportRejectsQuery;
use App\Import\UI\Http\DTO\ImportRejectQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/import-rejects', name: 'app_admin_import_reject_list')]
final class ListImportRejectsController extends AbstractController
{
    public function __construct(
        private readonly ListImportRejectsQuery $query,
        private readonly HospitalRepository $hospitalRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] ImportRejectQueryParametersDTO $filters,
    ): Response {
        $paginator = $this->query->getPaginator($filters);

        return $this->render('@Admin/import_rejects/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_admin_import_reject_list',
            'sortBy' => $filters->sortBy,
            'orderBy' => $filters->orderBy,
            'filters' => $filters,
            'activeFilterCount' => $this->countActiveFilters($filters),
            'hospitals' => $this->hospitalRepository->findAll(),
        ]);
    }

    private function countActiveFilters(ImportRejectQueryParametersDTO $filters): int
    {
        $active = 0;

        foreach (['importId', 'hospitalId', 'search'] as $field) {
            $value = $filters->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$active;
            }
        }

        return $active;
    }
}
