<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\Controller\MciCases;

use App\Allocation\Application\Explore\ExploreFilterOptionsProvider;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\MciCaseRepository;
use App\Allocation\UI\Http\DTO\MciCaseQueryParametersDTO;
use App\Import\Infrastructure\Repository\ImportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/mci_case', name: 'app_explore_mci_case_list', methods: ['GET'])]
final class ListMciCasesController extends AbstractController
{
    public function __construct(
        private readonly MciCaseRepository $mciCaseRepository,
        private readonly ExploreFilterOptionsProvider $filterOptionsProvider,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ImportRepository $importRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] MciCaseQueryParametersDTO $query,
    ): Response {
        $importName = null;
        if (null !== $query->importId) {
            $importName = $this->importRepository->find($query->importId)?->getName();
        }

        $paginator = $this->mciCaseRepository->getListPaginator($query);

        return $this->render('@Allocation/mci_cases/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_mci_case_list',
            'sortBy' => $query->sortBy,
            'orderBy' => $query->orderBy,
            'filters' => $query,
            'activeFilterCount' => $this->countActiveFilters($query),
            'hospitals' => $this->hospitalRepository->findFilterSummaries(),
            'importName' => $importName,
            'urgencies' => AllocationUrgency::cases(),
            'transportTypes' => AllocationTransportType::cases(),
            'states' => $this->filterOptionsProvider->states(),
            'dispatchAreas' => $this->filterOptionsProvider->dispatchAreas(),
            'indications' => $this->filterOptionsProvider->indications(),
            'infections' => $this->filterOptionsProvider->infections(),
            'departments' => $this->filterOptionsProvider->departments(),
            'specialities' => $this->filterOptionsProvider->specialities(),
            'occasions' => $this->filterOptionsProvider->occasions(),
        ]);
    }

    private function countActiveFilters(MciCaseQueryParametersDTO $query): int
    {
        $activeFilterCount = 0;

        if (null !== $query->normalizedMciId()) {
            ++$activeFilterCount;
        }

        if (null !== $query->normalizedSearch()) {
            ++$activeFilterCount;
        }

        if (null !== $query->importId) {
            ++$activeFilterCount;
        }

        if ($query->hasArrivalAtRange()) {
            ++$activeFilterCount;
        }

        foreach ([
            'hospital',
            'state',
            'dispatchArea',
            'urgency',
            'transportType',
            'department',
            'speciality',
            'departmentWasClosed',
            'requiresResus',
            'requiresCathlab',
            'isVentilated',
            'isShock',
            'isCPR',
            'isPregnant',
            'isWithPhysician',
            'indication',
            'occasion',
            'infection',
        ] as $field) {
            $value = $query->{$field};
            if (null !== $value && '' !== $value) {
                ++$activeFilterCount;
            }
        }

        return $activeFilterCount;
    }
}
