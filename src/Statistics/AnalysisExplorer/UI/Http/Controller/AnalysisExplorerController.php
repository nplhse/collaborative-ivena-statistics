<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Application\ExplorerConfigMapper;
use App\Statistics\AnalysisExplorer\Application\SavedExplorerViewLoader;
use App\Statistics\Application\DTO\StatisticsFilter;
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
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisExplorerController extends AbstractController
{
    public function __construct(
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly DefaultAnalysisViewFactory $defaultAnalysisViewFactory,
        private readonly ExplorerConfigMapper $explorerConfigMapper,
        private readonly SavedExplorerViewLoader $savedExplorerViewLoader,
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
        $defaultConfig = $this->defaultAnalysisViewFactory->createDefault($pageContext->filter);

        return $this->renderExplorer($pageContext, [
            'explorerAppliedConfigState' => $this->explorerConfigMapper->toStateArray($defaultConfig),
            'savedViewTitle' => null,
            'savedViewSlug' => null,
            'initialConfigWarning' => null,
            'libraryUrl' => $this->libraryUrl($request),
        ]);
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
        $loadResult = $this->savedExplorerViewLoader->load($view, $pageContext->filter, $user);
        if ($loadResult->notFound) {
            throw new NotFoundHttpException(sprintf('Explorer view "%s" was not found.', $view));
        }

        $initialConfigWarning = null;
        if ([] !== $loadResult->warnings) {
            $initialConfigWarning = $this->translator->trans($loadResult->warnings[0]);
        }

        return $this->renderExplorer($pageContext, [
            'explorerAppliedConfigState' => $loadResult->state,
            'savedViewTitle' => $loadResult->view?->getTitle(),
            'savedViewSlug' => $loadResult->view?->getSlug(),
            'initialConfigWarning' => $initialConfigWarning,
            'libraryUrl' => $this->libraryUrl($request),
        ]);
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
            $this->addFlash('error', $publicRedirect['notice']->value);
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
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $this->router->generate('app_stats_analysis_library', $query);
    }
}
