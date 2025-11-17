<?php

namespace App\Controller\Statistics;

use App\Service\Statistics\HospitalCompositionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/stats/hospitals', name: 'app_stats_hospital_composition')]
final class HospitalCompositionController extends AbstractController
{
    public function __construct(
        private HospitalCompositionService $hospitalCompositionService,
    ) {}

    public function __invoke(): Response
    {
        $stats = $this->hospitalCompositionService->compute();

        return $this->render('stats/hospitals/composition.html.twig', [
            'stats' => $stats,
        ]);
    }
}
