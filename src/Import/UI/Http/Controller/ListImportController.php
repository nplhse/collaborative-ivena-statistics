<?php

declare(strict_types=1);

namespace App\Import\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Import\Application\Service\ImportListAccess;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\Query\ListImportsQuery;
use App\Import\UI\Http\DTO\ListImportQueryParametersDTO;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/import', name: 'app_import_index')]
#[IsGranted('ROLE_USER')]
final class ListImportController extends AbstractController
{
    public function __construct(
        private readonly ListImportsQuery $listImportsQuery,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ImportListAccess $importListAccess,
    ) {
    }

    public function __invoke(
        #[MapQueryString] ListImportQueryParametersDTO $query,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException('You must be logged in to view imports.');
        }

        $paginator = $this->listImportsQuery->getPaginator($user, $query);

        return $this->render('@Import/index.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_import_index',
            'search' => $query->search,
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'filters' => $query,
            'activeFilterCount' => $this->countActiveFilters($query),
            'hospitals' => $this->hospitalRepository->findAccessibleHospitalSummaries($user),
            'owners' => $this->importListAccess->resolveOwnerChoices($user),
            'statuses' => ImportStatus::cases(),
        ]);
    }

    private function countActiveFilters(ListImportQueryParametersDTO $query): int
    {
        $count = 0;
        $filterFields = ['search', 'hospitalId', 'ownerId', 'status'];

        foreach ($filterFields as $field) {
            $value = $query->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$count;
            }
        }

        if (!$query->isDefaultCreatedFrom()) {
            ++$count;
        }

        if (!$query->isDefaultCreatedUntil()) {
            ++$count;
        }

        return $count;
    }
}
