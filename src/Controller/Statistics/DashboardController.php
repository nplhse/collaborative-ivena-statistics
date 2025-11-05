<?php

// src/Controller/Statistics/DashboardController.php

declare(strict_types=1);

namespace App\Controller\Statistics;

use App\Model\DashboardContext;
use App\Model\Scope;
use App\Service\Statistics\DashboardCohortStatsReader;
use App\Service\Statistics\DashboardCohortSumsReader;
use App\Service\Statistics\DashboardCountsReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class DashboardController extends AbstractController
{
    #[Route('/stats/dashboard', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
        DashboardCountsReader $countsReader,
        DashboardCohortSumsReader $cohortSumsReader,
        DashboardCohortStatsReader $cohortStatsReader,
    ): Response {
        $context = DashboardContext::fromQuery($request->query->all());
        $scope = Scope::fromDashboardContext($context);

        $isCohortScope = \in_array(
            $scope->scopeType,
            ['hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'],
            true
        );

        if ($isCohortScope) {
            $tpl = [
                'panel' => null,
                'cohortPanel' => [
                    'sums' => $cohortSumsReader->read($scope),
                    'stats' => $cohortStatsReader->read($scope),
                ],
                'scope' => $scope,
                'context' => $context,
            ];
        } else {
            $tpl = [
                'panel' => $countsReader->read($scope),
                'cohortPanel' => null,
                'scope' => $scope,
                'context' => $context,
            ];
        }

        return $this->render('dashboard/index.html.twig', $tpl);
    }
}
