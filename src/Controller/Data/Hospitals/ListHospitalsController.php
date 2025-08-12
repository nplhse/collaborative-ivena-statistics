<?php

namespace App\Controller\Data\Hospitals;

use App\DataTransferObjects\HospitalQueryParametersDTO;
use App\Repository\HospitalRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryString;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/data/hospital', name: 'app_data_hospital_list')]
final class ListHospitalsController extends AbstractController
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
    ) {
    }

    public function __invoke(
        #[MapQueryString] HospitalQueryParametersDTO $queryParametersDTO,
    ): Response {
        $paginator = $this->hospitalRepository->getHospitalListPaginator($queryParametersDTO);

        return $this->render('data/hospitals/list.html.twig', [
            'paginator' => $paginator,
            'search' => $queryParametersDTO->search,
            'sortBy' => $queryParametersDTO->sortBy,
            'orderBy' => $queryParametersDTO->orderBy,
        ]);
    }
}
