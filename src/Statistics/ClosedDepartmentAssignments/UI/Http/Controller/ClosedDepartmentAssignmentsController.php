<?php

declare(strict_types=1);

namespace App\Statistics\ClosedDepartmentAssignments\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsCriteriaFactory;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentAssignmentsService;
use App\Statistics\ClosedDepartmentAssignments\Application\ClosedDepartmentNamedCard;
use App\Statistics\ClosedDepartmentAssignments\Application\DTO\ClosedDepartmentNamedCardFrame;
use App\Statistics\UI\Http\Controller\AnalysisContextViewModelFactory;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class ClosedDepartmentAssignmentsController extends AbstractController
{
    public function __construct(
        private readonly ClosedDepartmentAssignmentsService $dashboardService,
        private readonly ClosedDepartmentAssignmentsCriteriaFactory $criteriaFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly ClosedDepartmentChartPayloadFactory $chartPayloadFactory,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
        private readonly AnalysisContextViewModelFactory $analysisContextViewModelFactory,
    ) {
    }

    #[Route('/statistics/closed-department-assignments', name: 'app_stats_closed_department_assignments', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_closed_department_assignments', $publicRedirect['query']);
        }

        $criteria = $this->criteriaFactory->create($user, $filter, $this->isGranted('ROLE_PARTICIPANT'));
        $result = $this->dashboardService->buildSummary($criteria);

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_closed_department_assignments',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_closed_department_assignments',
            $filter,
        );

        return $this->render('@Statistics/closed_department_assignments/index.html.twig', [
            'dataQualityLazyLoad' => true,
            'dataQualityDrawerUrl' => $this->navigationUrlBuilder->build($request, 'app_stats_data_quality_drawer'),
            'dashboard' => $result,
            'chartPayload' => $this->chartPayloadFactory->createSummary($result->timeSeries, $result->heatmap),
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
            'cohortScopeChoices' => $pageViewModel->cohortScopeChoices,
            'statsCohortDropdownSelectedName' => $pageViewModel->cohortDropdownSelectedName,
            'statsScopePrimaryMenu' => $pageViewModel->scopePrimaryMenu,
            'statsScopeSecondaryMenu' => $pageViewModel->scopeSecondaryMenu,
            'statsShowScopeSecondaryPicker' => $pageViewModel->showScopeSecondaryPicker,
            'statsScopePrimaryDropdownLabel' => $pageViewModel->scopePrimaryDropdownLabel,
            'statsScopeSecondaryDropdownLabel' => $pageViewModel->scopeSecondaryDropdownLabel,
            'statsPeriodUrls' => $pageViewModel->periodUrls,
            'accessibleHospitals' => $pageViewModel->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $pageViewModel->hospitalDropdownSelectedName,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
            'overviewPeriodViewModel' => $overviewPeriodViewModel,
            'statsUseOverviewPeriodControls' => true,
            'statsAnalysisContext' => $this->analysisContextViewModelFactory->create(
                $request,
                'app_stats_closed_department_assignments',
                $user,
                $filter,
                $pageViewModel->headingScope,
                $overviewPeriodViewModel->headingLabel,
            ),
            'rankingsFrameUrl' => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_closed_department_assignments_rankings',
                ['closedCount' => $result->kpis->closedCount],
            ),
            'dispatchCardFrame' => $this->namedCardFrame(
                $request,
                ClosedDepartmentNamedCard::DispatchArea,
                $result->kpis->closedCount,
            ),
            'detailsFrameUrl' => $this->navigationUrlBuilder->build(
                $request,
                'app_stats_closed_department_assignments_details',
            ),
        ]);
    }

    private function namedCardFrame(
        Request $request,
        ClosedDepartmentNamedCard $card,
        int $closedCount,
    ): ClosedDepartmentNamedCardFrame {
        return ClosedDepartmentNamedCardFrame::from(
            $card,
            $this->navigationUrlBuilder->build(
                $request,
                'app_stats_closed_department_assignments_card',
                ['card' => $card->value, 'closedCount' => $closedCount],
            ),
        );
    }
}
