<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionRegistry;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class PivotTablesController extends AbstractController
{
    private const string DEFAULT_PIVOT_KEY = 'allocation_pivot';

    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly AnalysisRequestModelFactory $analysisRequestModelFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly AnalysisPagePresenter $analysisPagePresenter,
        private readonly AnalysisDefinitionRegistry $analysisDefinitionRegistry,
        private readonly ComparisonScopeResolver $comparisonScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsExplorerViewModelFactory $statisticsExplorerViewModelFactory,
        private readonly StatisticsFilterDrawerStateFactory $statisticsFilterDrawerStateFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
    ) {
    }

    #[Route('/statistics/pivot', name: 'app_stats_pivot_tables', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_pivot_tables', $publicRedirect['query']);
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_pivot_tables',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }

        $analysisRequest = $this->analysisRequestModelFactory->fromRequest($request);
        $definition = $this->analysisDefinitionRegistry->get($analysisRequest->analysisKey);
        if (!$definition instanceof \App\Statistics\Application\Analysis\AnalysisDefinitionInterface || !$definition->isPivotLike()) {
            $query = $request->query->all();
            $query[StatisticsQueryKeys::ANALYSIS] = self::DEFAULT_PIVOT_KEY;

            return $this->redirectToRoute('app_stats_pivot_tables', $query);
        }

        $analysisKey = $definition->key();
        $comparisonFilter = $this->comparisonScopeResolver->resolve($request, $user, $filter);
        $context = $this->statisticsContextFactory->create(
            $user,
            $filter,
            null,
            $analysisRequest->rows,
            $analysisRequest->cols,
            $analysisRequest->measure,
            $comparisonFilter,
        );

        $analysisWidget = $definition->build(
            $context,
            'table',
            'bar',
            $analysisRequest->dimension,
            $analysisRequest->chartMeasure,
        );
        $analysisPage = $this->analysisPagePresenter->present(
            $request,
            $analysisRequest,
            $analysisKey,
            $analysisWidget,
            $comparisonFilter,
            $this->analysisDefinitionRegistry->all(),
        );
        $drawerState = $this->statisticsFilterDrawerStateFactory->fromRequest($request);
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create($request, 'app_stats_pivot_tables', $filter);

        return $this->render('@Statistics/analysis/index.html.twig', [
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
            'analysisPage' => $analysisPage,
            'statsExplorerSections' => $this->statisticsExplorerViewModelFactory->create($request, 'pivot_tables', $analysisKey),
            'statsFilterDrawerValues' => $drawerState['values'],
            'statsActiveFilterCount' => $drawerState['activeCount'],
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_pivot_tables'),
            'statsBreadcrumbLabelKey' => 'link.stats.pivot_tables',
        ]);
    }
}
