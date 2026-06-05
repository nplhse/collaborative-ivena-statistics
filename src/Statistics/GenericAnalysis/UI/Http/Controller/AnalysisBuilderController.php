<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisConfigResolver;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisRouteContext;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AnalysisBuilderController extends AbstractController
{
    private const string REFERENCE_VIEW_KEY = 'allocations_by_month';

    public function __construct(
        private readonly GenericAnalysisConfigResolver $configResolver,
        private readonly GenericAnalysisPageViewModelFactory $pageViewModelFactory,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    #[Route('/statistics/analytics/builder', name: 'app_stats_analytics_builder', methods: ['GET'])]
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

            return $this->redirectToRoute('app_stats_analytics_builder', $publicRedirect['query']);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scopeCriteria = $this->statisticsScopeResolver->resolveCriteria($context);
        $periodBounds = StatisticsPeriodResolver::resolve($filter);

        try {
            $config = $this->configResolver->resolve(
                self::REFERENCE_VIEW_KEY,
                $request,
                $scopeCriteria,
                $periodBounds,
                $filter,
                $user,
            );
        } catch (UnknownAnalysisDimensionException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $routeContext = GenericAnalysisRouteContext::forAnalyticsView(self::REFERENCE_VIEW_KEY);

        return $this->render('@Statistics/analytics_library/builder.html.twig', [
            'configPage' => $this->pageViewModelFactory->create(
                $request,
                self::REFERENCE_VIEW_KEY,
                $config,
                $filter,
                $user,
                $routeContext,
            ),
            'libraryUrl' => $this->router->generate('app_stats_analytics_library', $this->scopeQuery($request)),
            'isLoggedIn' => $this->statisticsPageViewModelFactory->create(
                $request,
                'app_stats_analytics_builder',
                $user,
                $filter,
            )->isLoggedIn,
        ]);
    }

    /**
     * @return array<string, string>
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
        ] as $key) {
            if ($request->query->has($key)) {
                $query[$key] = $request->query->getString($key);
            }
        }

        return $query;
    }
}
