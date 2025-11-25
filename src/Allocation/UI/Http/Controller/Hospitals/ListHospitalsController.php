<?php

namespace App\Allocation\UI\Http\Controller\Hospitals;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Repository\DispatchAreaRepository;
use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Allocation\Infrastructure\Repository\StateRepository;
use App\Allocation\UI\Http\DTO\HospitalQueryParametersDTO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/explore/hospital', name: 'app_explore_hospital_list')]
final class ListHospitalsController extends AbstractController
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private DispatchAreaRepository $dispatchAreaRepository,
        private StateRepository $stateRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] HospitalQueryParametersDTO $queryParametersDTO,
    ): Response {
        $paginator = $this->hospitalRepository->getHospitalListPaginator($queryParametersDTO);

        return $this->render('@Allocation/hospitals/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_explore_hospital_list',
            'search' => $queryParametersDTO->search,
            'sortBy' => $queryParametersDTO->sortBy,
            'orderBy' => $queryParametersDTO->orderBy,
            'filters' => $queryParametersDTO,
            'activeFilterCount' => $this->countActiveFilters($queryParametersDTO),
            'tiers' => HospitalTier::cases(),
            'locations' => HospitalLocation::cases(),
            'sizes' => HospitalSize::cases(),
            'states' => $this->stateRepository->findAll(),
            'dispatchAreas' => $this->dispatchAreaRepository->findAll(),
        ]);
    }

    private function countActiveFilters(HospitalQueryParametersDTO $queryParametersDTO): int
    {
        $activeFilterCount = 0;

        $filterFields = [
            'tier',
            'location',
            'size',
            'dispatchArea',
            'state',
            'participating',
            'search',
        ];

        foreach ($filterFields as $field) {
            $value = $queryParametersDTO->{$field} ?? null;
            if (null !== $value && '' !== $value) {
                ++$activeFilterCount;
            }
        }

        return $activeFilterCount;
    }
}
