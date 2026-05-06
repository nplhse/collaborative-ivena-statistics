<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\ClinicalFeaturesProvider;
use App\Statistics\Application\HospitalSummaryProvider;
use App\Statistics\Application\OverviewDashboardProvider;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OverviewDashboardProvider $overviewDashboardProvider,
        private readonly HospitalSummaryProvider $hospitalSummaryProvider,
        private readonly ClinicalFeaturesProvider $clinicalFeaturesProvider,
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
    ) {
    }

    #[Route('/statistics/', name: 'app_stats_dashboard', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->getUser();
        $filter = $this->statisticsFilterFactory->createFromRequest(
            $request,
            $user instanceof User ? $user : null,
        );
        $context = new StatisticsContext(
            $user instanceof User ? $user : null,
            $filter,
        );
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_dashboard',
            $user instanceof User ? $user : null,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }
        $chartPairWidget = array_find($this->overviewDashboardProvider->build($context), fn ($widget): bool => StatisticWidgetType::ChartPair === $widget->type);

        return $this->render('@Statistics/dashboard/index.html.twig', [
            'chartPairWidget' => $chartPairWidget,
            'hospitalSummaryWidgets' => $this->hospitalSummaryProvider->build($context),
            'clinicalFeatureWidgets' => $this->clinicalFeaturesProvider->build($context),
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
            'statsPeriodUrls' => $pageViewModel->periodUrls,
            'accessibleHospitals' => $pageViewModel->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $pageViewModel->hospitalDropdownSelectedName,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $pageViewModel->headingPeriod,
        ]);
    }
}
