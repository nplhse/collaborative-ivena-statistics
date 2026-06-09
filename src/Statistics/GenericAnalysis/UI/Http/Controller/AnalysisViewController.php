<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Application\AnalysisViewResolver;
use App\Statistics\GenericAnalysis\Application\AnalysisViewUsageTracker;
use App\Statistics\GenericAnalysis\Application\Contract\CustomAnalysisAccessInterface;
use App\Statistics\GenericAnalysis\Application\FavoriteAnalysisViewService;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisService;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisViewException;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisRouteContext;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AnalysisViewController extends AbstractController
{
    public function __construct(
        private readonly AnalysisViewResolver $viewResolver,
        private readonly GenericAnalysisService $genericAnalysisService,
        private readonly GenericAnalysisPageViewModelFactory $customizeViewModelFactory,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly GenericAnalysisTableViewModelFactory $tableViewModelFactory,
        private readonly GenericAnalysisChartViewModelFactory $chartViewModelFactory,
        private readonly FavoriteAnalysisViewService $favoriteService,
        private readonly AnalysisViewUsageTracker $usageTracker,
        private readonly UrlGeneratorInterface $router,
        private readonly CustomAnalysisAccessInterface $customAnalysisAccess,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
    ) {
    }

    #[Route('/statistics/analytics/view/{viewKey}', name: 'app_stats_analytics_view', methods: ['GET'])]
    public function __invoke(
        string $viewKey,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_analytics_view', array_merge(
                ['viewKey' => $viewKey],
                $publicRedirect['query'],
            ));
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scopeCriteria = $this->statisticsScopeResolver->resolveCriteria($context);
        $periodBounds = StatisticsPeriodResolver::resolve($filter);

        try {
            $resolved = $this->viewResolver->resolveSystemView(
                $viewKey,
                $request,
                $scopeCriteria,
                $periodBounds,
                $filter,
                $user,
            );
        } catch (UnknownAnalysisViewException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        } catch (UnknownAnalysisDimensionException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        if ($user instanceof User) {
            $this->usageTracker->recordSystemViewOpen($user, $viewKey);
        }

        $result = $this->genericAnalysisService->run($resolved->config->displayTitle, $resolved->config->query);
        $routeContext = GenericAnalysisRouteContext::forAnalyticsView($viewKey);

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_analytics_view',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_analytics_view',
            $filter,
        );

        $canCustomize = $this->customAnalysisAccess->canUseCustomAnalysis($user);
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/analytics_library/view.html.twig', [
            'dataQualityReport' => $dataQualityReport,
            'viewKey' => $viewKey,
            'canCustomize' => $canCustomize,
            'analysisView' => $resolved->view,
            'analysisResult' => $result,
            'genericAnalysisTable' => $this->tableViewModelFactory->create(
                $request,
                $resolved->view->presetKey(),
                $result,
                $resolved->config->primaryDimensionKey,
                $routeContext,
            ),
            'genericAnalysisChart' => $this->chartViewModelFactory->create(
                $request,
                $resolved->view->presetKey(),
                $resolved->config->query,
                $result,
                $routeContext,
            ),
            'customizePage' => $this->customizeViewModelFactory->create(
                $request,
                $resolved->view->presetKey(),
                $resolved->config,
                $filter,
                $user,
                $routeContext,
            ),
            'isFavorite' => $user instanceof User && $this->favoriteService->isSystemFavorite($user, $viewKey),
            'canFavorite' => $user instanceof User,
            'favoriteToggleUrl' => $user instanceof User
                ? $this->router->generate('app_stats_analytics_favorite_toggle', ['viewKey' => $viewKey])
                : null,
            'saveViewUrl' => $canCustomize
                ? $this->router->generate('app_stats_analytics_saved_create')
                : null,
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
        ]);
    }
}
