<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Application\AnalysisViewResolver;
use App\Statistics\GenericAnalysis\Application\AnalysisViewUsageTracker;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisService;
use App\Statistics\GenericAnalysis\Application\SavedAnalysisViewService;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisViewException;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisRouteContext;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
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
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class SavedAnalysisViewController extends AbstractController
{
    public function __construct(
        private readonly SavedAnalysisViewRepository $savedViewRepository,
        private readonly SavedAnalysisViewService $savedViewService,
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
        private readonly AnalysisViewUsageTracker $usageTracker,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
    ) {
    }

    #[IsGranted('ROLE_PARTICIPANT')]
    #[Route('/statistics/analytics/saved/{id}', name: 'app_stats_analytics_saved', methods: ['GET'])]
    public function show(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            return $this->redirectToRoute('app_stats_analytics_saved', array_merge(
                ['id' => $id],
                $publicRedirect['query'],
            ));
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scopeCriteria = $this->statisticsScopeResolver->resolveCriteria($context);
        $periodBounds = StatisticsPeriodResolver::resolve($filter);

        try {
            $resolved = $this->viewResolver->resolveSavedView(
                $id,
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

        $saved = $this->savedViewRepository->findForOwner($id, $user);
        if ($saved instanceof \App\Statistics\Domain\Entity\SavedAnalysisView) {
            $this->usageTracker->recordSavedViewOpen($user, $saved);
        }

        $result = $this->genericAnalysisService->run($resolved->config->displayTitle, $resolved->config->query);
        $routeContext = GenericAnalysisRouteContext::forSavedView($id);

        return $this->render('@Statistics/analytics_library/view.html.twig', $this->viewPayload(
            $request,
            $user,
            $filter,
            $resolved->view->key,
            $resolved->view,
            $resolved->config,
            $result,
            $routeContext,
            false,
            null,
        ));
    }

    #[IsGranted('ROLE_PARTICIPANT')]
    #[Route('/statistics/analytics/saved', name: 'app_stats_analytics_saved_create', methods: ['POST'])]
    public function create(
        Request $request,
        #[CurrentUser] User $user,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        if (!$this->isCsrfTokenValid('analytics_save_view', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $title = trim((string) $request->request->get('title', ''));
        if ('' === $title) {
            $this->addFlash('error', 'stats.analytics_library.save.title_required');

            return $this->redirect($request->headers->get('referer', '/statistics/analytics/library'));
        }

        $config = new \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig(
            primaryDimensionKey: (string) $request->request->get('primary_dimension', 'month'),
            secondaryDimensionKey: $this->nullableString($request->request->get('secondary_dimension')),
            metricKeys: $this->metricKeysFromRequest($request),
            visualMetricKey: $this->nullableString($request->request->get('visual_metric')),
            chartType: $this->nullableString($request->request->get('chart_type')),
            includeNullBuckets: '1' === (string) $request->request->get('include_null'),
            layout: $this->nullableString($request->request->get('layout')),
            top: $request->request->has('top') ? (int) $request->request->get('top') : null,
        );

        $saved = $this->savedViewService->create(
            owner: $user,
            title: $title,
            config: $config,
            description: $this->nullableString($request->request->get('description')),
            sourceSystemViewKey: $this->nullableString($request->request->get('source_view_key')),
        );

        return $this->redirectToRoute('app_stats_analytics_saved', ['id' => $saved->getId()]);
    }

    #[IsGranted('ROLE_PARTICIPANT')]
    #[Route('/statistics/analytics/saved/{id}', name: 'app_stats_analytics_saved_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        Request $request,
        #[CurrentUser] User $user,
    ): \Symfony\Component\HttpFoundation\RedirectResponse {
        if (!$this->isCsrfTokenValid('analytics_delete_view_'.$id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $saved = $this->savedViewRepository->findForOwner($id, $user);
        if (!$saved instanceof \App\Statistics\Domain\Entity\SavedAnalysisView) {
            throw $this->createNotFoundException();
        }

        $this->savedViewService->delete($saved);

        return $this->redirectToRoute('app_stats_analytics_library');
    }

    /**
     * @return array<string, mixed>
     */
    private function viewPayload(
        Request $request,
        User $user,
        StatisticsFilter $filter,
        string $viewKey,
        \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition $view,
        \App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig $config,
        \App\Statistics\GenericAnalysis\Application\DTO\NormalizedAnalysisResult $result,
        GenericAnalysisRouteContext $routeContext,
        bool $canFavorite,
        ?string $favoriteToggleUrl,
    ): array {
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_analytics_saved',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_analytics_saved',
            $filter,
        );

        return [
            'dataQualityReport' => $this->dataQualityReportFactory->create(
                $filter,
                $user,
                $pageViewModel,
                $overviewPeriodViewModel,
            ),
            'viewKey' => $viewKey,
            'canCustomize' => true,
            'analysisView' => $view,
            'analysisResult' => $result,
            'genericAnalysisTable' => $this->tableViewModelFactory->create(
                $request,
                $view->presetKey(),
                $result,
                $config->primaryDimensionKey,
                $routeContext,
            ),
            'genericAnalysisChart' => $this->chartViewModelFactory->create(
                $request,
                $view->presetKey(),
                $config->query,
                $result,
                $routeContext,
            ),
            'customizePage' => $this->customizeViewModelFactory->create(
                $request,
                $view->presetKey(),
                $config,
                $filter,
                $user,
                $routeContext,
            ),
            'isFavorite' => false,
            'canFavorite' => $canFavorite,
            'favoriteToggleUrl' => $favoriteToggleUrl,
            'saveViewUrl' => $this->generateUrl('app_stats_analytics_saved_create'),
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
            'isLoggedIn' => true,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
            'overviewPeriodViewModel' => $overviewPeriodViewModel,
            'statsUseOverviewPeriodControls' => true,
        ];
    }

    /**
     * @return list<string>
     */
    private function metricKeysFromRequest(Request $request): array
    {
        $metrics = $request->request->all('metrics');
        if ([] === $metrics) {
            $metrics = $request->request->all(GenericAnalysisQueryKeys::METRICS);
        }

        return array_values(array_filter(
            $metrics,
            static fn (mixed $metric): bool => \is_string($metric) && '' !== $metric,
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        if (!\is_string($value) || '' === trim($value)) {
            return null;
        }

        return $value;
    }
}
