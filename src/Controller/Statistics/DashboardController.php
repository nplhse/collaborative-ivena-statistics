<?php

declare(strict_types=1);

namespace App\Controller\Statistics;

use App\Model\DashboardContext;
use App\Model\Scope;
use App\Service\Statistics\AgeChartDataLoader;
use App\Service\Statistics\AgeMetricPresets;
use App\Service\Statistics\DashboardCohortStatsReader;
use App\Service\Statistics\DashboardCohortSumsReader;
use App\Service\Statistics\DashboardCountsReader;
use App\Service\Statistics\HourlyChartDataLoader;
use App\Service\Statistics\HourlyMetricPresets;
use App\Service\Statistics\TopCategoriesReader;
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
        HourlyChartDataLoader $hourlyChartDataLoader,
        TopCategoriesReader $topCategoriesReader,
        AgeChartDataLoader $ageChartDataLoader,
    ): Response {
        $context = DashboardContext::fromQuery($request->query->all());
        $scope = Scope::fromDashboardContext($context);

        $preset = $request->query->get('hourly', 'total');
        $metrics = HourlyMetricPresets::metricsFor($preset);

        $hourlyPayload = $hourlyChartDataLoader->buildPayload($scope, $metrics);

        $agePreset = $request->query->get('age', 'total');
        $ageMetrics = AgeMetricPresets::metricsFor($agePreset);
        $ageMode = $request->query->get('age_mode', 'count');
        $agePayload = $ageChartDataLoader->buildPayload($scope, $ageMetrics);

        $isCohortScope = \in_array(
            $scope->scopeType,
            ['hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'],
            true
        );

        $topCats = $topCategoriesReader->read(
            $scope->scopeType,
            $scope->scopeId,
            $scope->granularity,
            $scope->periodKey
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
                'hourly' => $hourlyPayload,
                'preset' => $preset,
                'presets' => HourlyMetricPresets::all(),
                'topCats' => $topCats,
                'age' => $agePayload,
                'agePreset' => $agePreset,
                'agePresets' => AgeMetricPresets::all(),
                'ageMode' => $ageMode,
            ];
        } else {
            $tpl = [
                'panel' => $countsReader->read($scope),
                'cohortPanel' => null,
                'scope' => $scope,
                'context' => $context,
                'hourly' => $hourlyPayload,
                'preset' => $preset,
                'presets' => HourlyMetricPresets::all(),
                'topCats' => $topCats,
                'age' => $agePayload,
                'agePreset' => $agePreset,
                'agePresets' => AgeMetricPresets::all(),
                'ageMode' => $ageMode,
            ];
        }

        return $this->render('dashboard/index.html.twig', $tpl);
    }
}
