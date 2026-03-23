<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Domain\Model\DashboardContext;
use App\Statistics\Domain\Model\Scope;
use App\Statistics\Infrastructure\Loader\AgeChartDataLoader;
use App\Statistics\Infrastructure\Loader\HourlyChartDataLoader;
use App\Statistics\Infrastructure\Presets\AgeMetricPresets;
use App\Statistics\Infrastructure\Presets\HourlyMetricPresets;
use App\Statistics\Infrastructure\Reader\DashboardCohortStatsReader;
use App\Statistics\Infrastructure\Reader\DashboardCohortSumsReader;
use App\Statistics\Infrastructure\Reader\DashboardCountsReader;
use App\Statistics\Infrastructure\Reader\TopCategoriesReader;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly DashboardCountsReader $countsReader,
        private readonly DashboardCohortSumsReader $cohortSumsReader,
        private readonly DashboardCohortStatsReader $cohortStatsReader,
        private readonly HourlyChartDataLoader $hourlyChartDataLoader,
        private readonly TopCategoriesReader $topCategoriesReader,
        private readonly AgeChartDataLoader $ageChartDataLoader,
    ) {
    }

    #[Route('/statistics/', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(
        Request $request,
    ): Response {
        $context = DashboardContext::fromQuery($request->query->all());
        $scope = Scope::fromDashboardContext($context);

        $preset = $request->query->get('hourly', 'total');
        $metrics = HourlyMetricPresets::metricsFor($preset);

        $hourlyPayload = $this->hourlyChartDataLoader->buildPayload($scope, $metrics);

        $agePreset = $request->query->get('age', 'total');
        $ageMetrics = AgeMetricPresets::metricsFor($agePreset);
        $ageMode = $request->query->get('age_mode', 'count');
        $agePayload = $this->ageChartDataLoader->buildPayload($scope, $ageMetrics);

        $isCohortScope = \in_array(
            $scope->scopeType,
            ['hospital_tier', 'hospital_size', 'hospital_location', 'hospital_cohort'],
            true
        );

        $topCats = $this->topCategoriesReader->read(
            $scope->scopeType,
            $scope->scopeId,
            $scope->granularity,
            $scope->periodKey
        );

        if ($isCohortScope) {
            $tpl = [
                'panel' => null,
                'cohortPanel' => [
                    'sums' => $this->cohortSumsReader->read($scope),
                    'stats' => $this->cohortStatsReader->read($scope),
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
                'panel' => $this->countsReader->read($scope),
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

        return $this->render('@Statistics/dashboard/index.html.twig', $tpl);
    }
}
