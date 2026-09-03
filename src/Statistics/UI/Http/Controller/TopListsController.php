<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use App\Statistics\Application\TopList\TopListComparisonAssembler;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class TopListsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly TopListsRequestModelFactory $topListsRequestModelFactory,
        private readonly TopListDefinitionRegistry $topListDefinitionRegistry,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly TopListsIndexPresenter $topListsIndexPresenter,
        private readonly TopListsPagePresenter $topListsPagePresenter,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsExplorerViewModelFactory $statisticsExplorerViewModelFactory,
        private readonly StatisticsFilterDrawerViewModelFactory $statisticsFilterDrawerViewModelFactory,
        private readonly StatisticsDrawerFilterFactory $statisticsDrawerFilterFactory,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly ComparisonScopeResolver $comparisonScopeResolver,
        private readonly TopListComparisonAssembler $topListComparisonAssembler,
    ) {
    }

    #[Route('/statistics/top-lists', name: 'app_stats_top_lists', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if ($request->query->has(StatisticsQueryKeys::REPORT)) {
            $report = (string) $request->query->get(StatisticsQueryKeys::REPORT);
            $query = $request->query->all();
            unset($query[StatisticsQueryKeys::REPORT]);

            return $this->redirectToRoute('app_stats_top_lists_show', ['report' => $report] + $query);
        }

        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_top_lists', $publicRedirect['query']);
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_top_lists',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', new TranslatableMessage('stats.overview.hospital_summary.unscoped_hint', domain: 'statistics'));
        }

        $indexPage = $this->topListsIndexPresenter->present($request);
        $overviewPeriodViewModel = $this->emptyPeriodViewModel();
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/top_lists/index.html.twig', $this->sharedTemplateVars(
            $pageViewModel,
            $overviewPeriodViewModel,
            $dataQualityReport,
            $request,
            'app_stats_top_lists',
            [
                'indexPage' => $indexPage,
                'statisticsHeadingPeriod' => '',
            ],
        ));
    }

    #[Route('/statistics/top-lists/{report}', name: 'app_stats_top_lists_show', requirements: ['report' => '[a-z0-9_]+'], methods: ['GET'])]
    public function show(
        Request $request,
        string $report,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_top_lists_show', ['report' => $report] + $publicRedirect['query']);
        }

        $definition = $this->topListDefinitionRegistry->get($report);
        if (!$definition instanceof TopListDefinitionInterface) {
            throw new NotFoundHttpException(sprintf('Unknown top list "%s".', $report));
        }

        $drawerFilter = $this->statisticsDrawerFilterFactory->fromRequest($request);
        $context = $this->statisticsContextFactory->create($user, $filter, drawerFilter: $drawerFilter);
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_top_lists_show',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', new TranslatableMessage('stats.overview.hospital_summary.unscoped_hint', domain: 'statistics'));
        }

        $topListsRequest = $this->topListsRequestModelFactory->fromQuery($request->query->all(), $report);
        $rankingA = $definition->fetchRanking($context, $topListsRequest->limit->queryLimit());

        $comparisonFilter = $this->comparisonScopeResolver->resolve(
            $request,
            $user,
            $filter,
            HospitalPermission::Statistics,
        );
        $comparison = null;
        $comparisonPageViewModel = null;
        $comparisonPeriodViewModel = null;
        if ($topListsRequest->compare) {
            $contextB = $this->statisticsContextFactory->create(
                $user,
                $comparisonFilter,
                drawerFilter: $drawerFilter,
            );
            $rankingB = $definition->fetchRanking($contextB, $topListsRequest->limit->queryLimit());
            $comparison = $this->topListComparisonAssembler->assemble($rankingA, $rankingB);
            $comparisonPageViewModel = $this->statisticsPageViewModelFactory->create(
                $request,
                'app_stats_top_lists_show',
                $user,
                $comparisonFilter,
            );
            $comparisonPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
                $request,
                'app_stats_top_lists_show',
                $comparisonFilter,
            );
        }

        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create($request, 'app_stats_top_lists_show', $filter);
        $topListsPage = $this->topListsPagePresenter->present(
            $request,
            $definition,
            $topListsRequest,
            $rankingA,
            $this->topListDefinitionRegistry->all(),
            $comparison,
            $filter,
            $comparisonFilter,
            $pageViewModel->headingScope,
            $overviewPeriodViewModel->headingLabel,
            $comparisonPageViewModel?->headingScope,
            $comparisonPeriodViewModel?->headingLabel,
        );
        $statsFilterDrawer = $this->statisticsFilterDrawerViewModelFactory->create($request);
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/top_lists/show.html.twig', $this->sharedTemplateVars(
            $pageViewModel,
            $overviewPeriodViewModel,
            $dataQualityReport,
            $request,
            'app_stats_top_lists_show',
            [
                'topListsPage' => $topListsPage,
                'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
                'statsShowFilterDrawer' => true,
                'statsUseOverviewPeriodControls' => true,
                'statsExplorerSections' => $this->statisticsExplorerViewModelFactory->create($request, 'top_lists', $definition->key()),
                'statsFilterDrawer' => $statsFilterDrawer,
                'statsTopListCompareEnabled' => $topListsPage->compareEnabled,
                'statsTopListCompareEnableUrl' => $topListsPage->compareEnableUrl,
                'statsTopListCompareDisableUrl' => $topListsPage->compareDisableUrl,
                'statsHideScopeControls' => $topListsPage->compareEnabled,
                'statsHidePeriodControls' => $topListsPage->compareEnabled,
                'statsTopListCatalogUrl' => $topListsPage->catalogListUrl,
            ],
        ));
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function sharedTemplateVars(
        StatisticsPageViewModel $pageViewModel,
        OverviewPeriodViewModel $overviewPeriodViewModel,
        mixed $dataQualityReport,
        Request $request,
        string $routeName,
        array $extra,
    ): array {
        return [
            'dataQualityReport' => $dataQualityReport,
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
            'overviewPeriodViewModel' => $overviewPeriodViewModel,
            'statsFilterDrawerResetUrl' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                removeKeys: StatisticsQueryKeys::DRAWER_FILTERS,
            ),
            ...$extra,
        ];
    }

    private function emptyPeriodViewModel(): OverviewPeriodViewModel
    {
        return new OverviewPeriodViewModel(
            '',
            '',
            null,
            false,
            [],
            [],
            null,
            null,
            null,
            null,
            false,
            false,
            false,
        );
    }
}
