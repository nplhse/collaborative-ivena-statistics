<?php

declare(strict_types=1);

namespace App\Statistics\HospitalPopulation\UI\Http\Controller;

use App\Statistics\HospitalPopulation\Application\HospitalPopulationDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HospitalPopulationController extends AbstractController
{
    public function __construct(
        private readonly HospitalPopulationDashboardService $dashboardService,
        private readonly HospitalPopulationChartPayloadFactory $chartPayloadFactory,
        private readonly HospitalPopulationMapPayloadFactory $mapPayloadFactory,
    ) {
    }

    #[Route('/statistics/hospital-population', name: 'app_stats_hospital_population', methods: ['GET'])]
    public function __invoke(): Response
    {
        $result = $this->dashboardService->build();

        return $this->render('@Statistics/hospital_population/index.html.twig', [
            'result' => $result,
            'chartPayload' => $this->chartPayloadFactory->create($result),
            'mapPayload' => $this->mapPayloadFactory->create($result),
        ]);
    }
}
