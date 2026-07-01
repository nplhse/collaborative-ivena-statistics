<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactoryRegistry;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewFavoriteService;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerChartRowLimit;
use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerQueryKeys;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisExplorerController extends AbstractController
{
    public function __construct(
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly DefaultAnalysisViewFactoryRegistry $defaultAnalysisViewFactory,
        private readonly ExplorerConfigMapper $explorerConfigMapper,
        private readonly SavedExplorerViewLoader $savedExplorerViewLoader,
        private readonly SavedExplorerViewFavoriteService $favoriteService,
        private readonly UrlGeneratorInterface $router,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/statistics/analysis/explorer', name: 'app_stats_analysis_explorer', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->maybeRedirect($request, $filter, 'app_stats_analysis_explorer');
        if ($redirect instanceof Response) {
            return $redirect;
        }

        $pageContext = $this->createPageContext($request, $user, $filter, 'app_stats_analysis_explorer');
        $dataSource = $this->resolveDataSource($request);
        $defaultConfig = $this->defaultAnalysisViewFactory->createDefault($dataSource, $pageContext->filter);
        $appliedState = $this->explorerConfigMapper->toStateArray($defaultConfig);
        $appliedState = $this->mergeChartTopQueryOverride($request, $appliedState);

        return $this->renderExplorer($pageContext, array_merge(
            $this->buildViewPresentation(null, $user),
            [
                'explorerAppliedConfigState' => $appliedState,
                'initialConfigWarning' => null,
                'libraryUrl' => $this->libraryUrl($request),
                'exportCsvUrl' => $this->exportCsvUrl($request),
            ],
        ));
    }

    #[Route('/statistics/analysis/explorer/{view}', name: 'app_stats_analysis_explorer_view', methods: ['GET'])]
    public function savedView(
        string $view,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $redirect = $this->maybeRedirect($request, $filter, 'app_stats_analysis_explorer_view', ['view' => $view]);
        if ($redirect instanceof Response) {
            return $redirect;
        }

        $pageContext = $this->createPageContext($request, $user, $filter, 'app_stats_analysis_explorer_view');
        $requestedDataSource = $this->resolveExplicitDataSource($request);
        $loadResult = $this->savedExplorerViewLoader->load($view, $pageContext->filter, $user, $requestedDataSource);
        if ($loadResult->notFound) {
            throw new NotFoundHttpException(sprintf('Explorer view "%s" was not found.', $view));
        }

        $initialConfigWarning = null;
        if ([] !== $loadResult->warnings) {
            $initialConfigWarning = $this->translator->trans($loadResult->warnings[0], [], 'statistics');
        }

        return $this->renderExplorer($pageContext, array_merge(
            $this->buildViewPresentation($loadResult->view, $user),
            [
                'explorerAppliedConfigState' => $this->mergeChartTopQueryOverride($request, $loadResult->state),
                'initialConfigWarning' => $initialConfigWarning,
                'libraryUrl' => $this->libraryUrl($request),
                'exportCsvUrl' => $this->exportCsvUrl($request),
            ],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildViewPresentation(?SavedExplorerView $view, ?User $user): array
    {
        $isParticipant = $user instanceof User && $this->isGranted('ROLE_PARTICIPANT');
        $savedViewId = $view?->getId();
        $isSystemView = $view?->isSystem() ?? false;
        $canSave = $view instanceof SavedExplorerView && $isParticipant && $view->isEditableBy($user);
        $canSaveAs = $isParticipant;
        $canFavorite = $user instanceof User && $view instanceof SavedExplorerView && null !== $savedViewId;
        $isFavorite = $canFavorite
            && $this->favoriteService->isFavorite($user, $view);

        return [
            'savedViewTitle' => $view?->getTitle(),
            'savedViewDescription' => $view?->getDescription(),
            'savedViewId' => $savedViewId,
            'isSystemView' => $isSystemView,
            'canSave' => $canSave,
            'canSaveAs' => $canSaveAs,
            'canFavorite' => $canFavorite,
            'isFavorite' => $isFavorite,
            'favoriteUrl' => $canFavorite
                ? $this->generateUrl('app_stats_analysis_explorer_favorite_toggle', ['id' => $savedViewId])
                : null,
            'favoriteToken' => $canFavorite
                ? 'explorer_favorite_'.$savedViewId
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    private function maybeRedirect(
        Request $request,
        StatisticsFilter $filter,
        string $routeName,
        array $routeParams = [],
    ): ?Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null === $publicRedirect) {
            return null;
        }

        if (null !== $publicRedirect['notice']) {
            $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
        }

        return $this->redirectToRoute($routeName, array_merge($routeParams, $publicRedirect['query']));
    }

    private function createPageContext(
        Request $request,
        ?User $user,
        StatisticsFilter $filter,
        string $routeName,
    ): AnalysisExplorerPageContext {
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            $routeName,
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            $routeName,
            $filter,
        );
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return new AnalysisExplorerPageContext(
            filter: $pageViewModel->filter,
            dataQualityReport: $dataQualityReport,
            isLoggedIn: $pageViewModel->isLoggedIn,
        );
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function renderExplorer(AnalysisExplorerPageContext $pageContext, array $extra): Response
    {
        return $this->render('@Statistics/analysis_explorer/index.html.twig', array_merge([
            'dataQualityReport' => $pageContext->dataQualityReport,
            'statisticsFilter' => $pageContext->filter,
            'isLoggedIn' => $pageContext->isLoggedIn,
        ], $extra));
    }

    private function libraryUrl(Request $request): string
    {
        return $this->router->generate('app_stats_analysis_library', $this->scopeQuery($request));
    }

    private function exportCsvUrl(Request $request): string
    {
        return $this->router->generate('app_stats_analysis_explorer_export_csv', $this->scopeQuery($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function scopeQuery(Request $request): array
    {
        $query = [];
        foreach ([
            StatisticsQueryKeys::SCOPE,
            StatisticsQueryKeys::HOSPITAL,
            StatisticsQueryKeys::COHORT,
            StatisticsQueryKeys::STATE,
            StatisticsQueryKeys::DISPATCH_AREA,
            StatisticsQueryKeys::PERIOD,
            StatisticsQueryKeys::YEAR,
            StatisticsQueryKeys::MONTH,
            StatisticsQueryKeys::QUARTER,
            ExplorerQueryKeys::DATA_SOURCE,
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function mergeChartTopQueryOverride(Request $request, array $state): array
    {
        if (!$request->query->has(ExplorerQueryKeys::CHART_TOP)) {
            return $state;
        }

        return $this->explorerConfigMapper->mergeChartRowLimitIntoState(
            $state,
            ExplorerChartRowLimit::fromValue($request->query->getString(ExplorerQueryKeys::CHART_TOP)),
        );
    }

    private function resolveDataSource(Request $request): AnalysisDataSourceKey
    {
        $raw = $request->query->getString(ExplorerQueryKeys::DATA_SOURCE, AnalysisDataSourceKey::Allocations->value);

        return AnalysisDataSourceKey::tryFrom($raw) ?? AnalysisDataSourceKey::Allocations;
    }

    private function resolveExplicitDataSource(Request $request): ?AnalysisDataSourceKey
    {
        if (!$request->query->has(ExplorerQueryKeys::DATA_SOURCE)) {
            return null;
        }

        $raw = $request->query->getString(ExplorerQueryKeys::DATA_SOURCE);

        return AnalysisDataSourceKey::tryFrom($raw);
    }
}
