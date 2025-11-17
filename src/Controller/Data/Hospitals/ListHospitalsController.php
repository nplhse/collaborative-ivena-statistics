<?php

namespace App\Controller\Data\Hospitals;

use App\DataTransferObjects\HospitalQueryParametersDTO;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Repository\DispatchAreaRepository;
use App\Repository\HospitalRepository;
use App\Repository\StateRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/hospital', name: 'app_data_hospital_list')]
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

        return $this->render('data/hospitals/list.html.twig', [
            'paginator' => $paginator,
            'pagination_route' => 'app_data_hospital_list',
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
