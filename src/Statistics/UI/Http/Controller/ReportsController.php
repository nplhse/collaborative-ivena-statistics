<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsDrawerFilterFactory;
use App\Statistics\Application\SummarizedReport\Exception\UnknownReportTypeException;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use App\Statistics\Application\SummarizedReport\ReportTypeInterface;
use App\Statistics\Application\SummarizedReport\ReportTypeRegistry;
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

final class ReportsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly ReportTypeRegistry $reportTypeRegistry,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly SummarizedReportsIndexPresenter $reportsIndexPresenter,
        private readonly SummarizedReportsPagePresenter $reportsPagePresenter,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsFilterDrawerViewModelFactory $statisticsFilterDrawerViewModelFactory,
        private readonly StatisticsDrawerFilterFactory $statisticsDrawerFilterFactory,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
    ) {
    }

    #[Route('/statistics/reports', name: 'app_stats_reports', methods: ['GET'])]
    public function index(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if ($request->query->has('type')) {
            $type = (string) $request->query->get('type');
            $query = $request->query->all();
            unset($query['type']);

            return $this->redirectToRoute('app_stats_reports_show', ['type' => $type] + $query);
        }

        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_reports', $publicRedirect['query']);
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_reports',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', new TranslatableMessage('stats.overview.hospital_summary.unscoped_hint', domain: 'statistics'));
        }

        $indexPage = $this->reportsIndexPresenter->present($request);
        $overviewPeriodViewModel = $this->fixedPeriodViewModel('');
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/reports/index.html.twig', $this->sharedTemplateVars(
            $pageViewModel,
            $overviewPeriodViewModel,
            $dataQualityReport,
            $request,
            'app_stats_reports',
            [
                'indexPage' => $indexPage,
                'statisticsHeadingPeriod' => '',
                'statsShowFilterDrawer' => false,
                'statsHidePeriodControls' => true,
            ],
        ));
    }

    #[Route('/statistics/reports/{type}', name: 'app_stats_reports_show', requirements: ['type' => '[a-z0-9_]+'], methods: ['GET'])]
    public function show(
        Request $request,
        string $type,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_reports_show', ['type' => $type] + $publicRedirect['query']);
        }

        $reportType = $this->reportTypeRegistry->get($type);
        if (!$reportType instanceof ReportTypeInterface) {
            throw new NotFoundHttpException(sprintf('Unknown report type "%s".', $type));
        }

        $showFilterDrawer = 'transport_time_profile' !== $reportType->key();
        $drawerFilter = $showFilterDrawer
            ? $this->statisticsDrawerFilterFactory->fromRequest($request)
            : null;
        $context = $this->statisticsContextFactory->create($user, $filter, drawerFilter: $drawerFilter);
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_reports_show',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', new TranslatableMessage('stats.overview.hospital_summary.unscoped_hint', domain: 'statistics'));
        }

        try {
            $buildResult = $reportType->build($context, $request->getLocale(), [
                'year' => $request->query->get('year'),
                'month' => $request->query->get('month'),
            ]);
        } catch (UnknownReportTypeException $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        $reportsPage = $this->reportsPagePresenter->present($request, $reportType, $buildResult);
        $statsFilterDrawer = $showFilterDrawer
            ? $this->statisticsFilterDrawerViewModelFactory->create($request)
            : null;
        $isMonthly = $buildResult->viewModel instanceof MonthlyReportView;
        if ($isMonthly) {
            $overviewPeriodViewModel = $reportsPage->periodNavigation ?? $this->fixedPeriodViewModel($reportsPage->periodLabel);
            $headingPeriod = $reportsPage->periodLabel;
        } else {
            $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
                $request,
                'app_stats_reports_show',
                $filter,
            );
            $headingPeriod = 'transport_time_profile' === $reportType->key()
                ? ''
                : $overviewPeriodViewModel->headingLabel;
        }
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/reports/show.html.twig', $this->sharedTemplateVars(
            $pageViewModel,
            $overviewPeriodViewModel,
            $dataQualityReport,
            $request,
            'app_stats_reports_show',
            [
                'reportsPage' => $reportsPage,
                'statisticsHeadingPeriod' => $headingPeriod,
                'statsShowFilterDrawer' => $showFilterDrawer,
                'statsFilterDrawer' => $statsFilterDrawer,
                'statsUseOverviewPeriodControls' => true,
                'statsHidePeriodControls' => false,
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
            'statsUseOverviewPeriodControls' => false,
            'statsFilterDrawerResetUrl' => $this->statisticsNavigationUrlBuilder->build(
                $request,
                $routeName,
                removeKeys: StatisticsQueryKeys::DRAWER_FILTERS,
            ),
            ...$extra,
        ];
    }

    private function fixedPeriodViewModel(string $headingLabel): OverviewPeriodViewModel
    {
        return new OverviewPeriodViewModel(
            $headingLabel,
            $headingLabel,
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
