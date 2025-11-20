<?php

namespace App\Controller\Statistics;

use App\Query\HospitalCubeQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/stats/hospitals/pivot', name: 'app_stats_hospital_pivot')]
final class HospitalPivotController extends AbstractController
{
    public function __construct(
        private readonly HospitalCubeQuery $cubeQuery,
    ) {
    }

    public function __invoke(): Response
    {
        $rows = $this->cubeQuery->fetchCubeRows();

        $dimensions = [
            'tier' => 'Hospital Tier',
            'location' => 'Hospital Location',
            'size' => 'Hospital Size',
            'state' => 'State',
            'dispatchArea' => 'Dispatch Area',
            'isParticipating' => 'Participation',
        ];

        $measures = [
            'hospitalCount' => 'Number of Hospitals',
            'avgBeds' => 'Average Beds',
            'allocationCount' => 'Count of Allocations',
        ];

        return $this->render('stats/hospitals/pivot.html.twig', [
            'cubeRows' => $rows,
            'dimensions' => $dimensions,
            'measures' => $measures,
        ]);
    }
}
