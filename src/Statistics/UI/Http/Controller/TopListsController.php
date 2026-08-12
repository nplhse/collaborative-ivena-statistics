<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use App\Statistics\Application\TopList\TopListDefinitionRegistry;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
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
        private readonly TopListsPagePresenter $topListsPagePresenter,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsExplorerViewModelFactory $statisticsExplorerViewModelFactory,
        private readonly StatisticsFilterDrawerViewModelFactory $statisticsFilterDrawerViewModelFactory,
        private readonly StatisticsDrawerFilterFactory $statisticsDrawerFilterFactory,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
    ) {
    }

    #[Route('/statistics/top-lists', name: 'app_stats_top_lists', methods: ['GET'])]
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

            return $this->redirectToRoute('app_stats_top_lists', $publicRedirect['query']);
        }

        $drawerFilter = $this->statisticsDrawerFilterFactory->fromRequest($request);
        $context = $this->statisticsContextFactory->create($user, $filter, drawerFilter: $drawerFilter);
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_top_lists',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', new TranslatableMessage('stats.overview.hospital_summary.unscoped_hint', domain: 'statistics'));
        }

        $topListsRequest = $this->topListsRequestModelFactory->fromQuery($request->query->all());
        $definition = $this->topListDefinitionRegistry->getOrFirst($topListsRequest->topListKey);
        $topListWidget = $definition->build($context, $topListsRequest->limit);
        $topListsPage = $this->topListsPagePresenter->present(
            $request,
            $definition,
            $topListsRequest,
            $topListWidget,
            $this->topListDefinitionRegistry->all(),
        );
        $statsFilterDrawer = $this->statisticsFilterDrawerViewModelFactory->create($request);
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create($request, 'app_stats_top_lists', $filter);
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/top_lists/index.html.twig', [
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
            'statisticsHeadingPeriod' => $overviewPeriodViewModel->headingLabel,
            'overviewPeriodViewModel' => $overviewPeriodViewModel,
            'statsUseOverviewPeriodControls' => true,
            'topListsPage' => $topListsPage,
            'statsExplorerSections' => $this->statisticsExplorerViewModelFactory->create($request, 'top_lists', $definition->key()),
            'statsFilterDrawer' => $statsFilterDrawer,
            'statsFilterDrawerResetUrl' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                'app_stats_top_lists',
                removeKeys: StatisticsQueryKeys::DRAWER_FILTERS,
            ),
        ]);
    }
}
