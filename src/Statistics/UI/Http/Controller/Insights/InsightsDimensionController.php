<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller\Insights;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightValueDirectoryService;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
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

final class InsightsDimensionController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly InsightsPageChromeFactory $chromeFactory,
        private readonly InsightsSubnavViewModelFactory $subnavFactory,
        private readonly InsightDimensionRegistry $registry,
        private readonly InsightValueDirectoryService $directoryService,
        private readonly StatisticsNavigationUrlBuilder $navigationUrlBuilder,
    ) {
    }

    #[Route(
        '/statistics/insights/{dimension}',
        name: 'app_stats_insights_dimension',
        requirements: ['dimension' => 'indications|indication-groups|specialities|assignments|departments|occasions|infections|secondary-transports'],
        methods: ['GET'],
    )]
    public function __invoke(
        Request $request,
        string $dimension,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $dimensionKey = InsightDimensionKey::tryFrom($dimension);
        if (!$dimensionKey instanceof InsightDimensionKey) {
            throw $this->createNotFoundException('Insight dimension not found.');
        }

        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', new TranslatableMessage($publicRedirect['notice']->value, domain: 'statistics'));
            }

            return $this->redirectToRoute('app_stats_insights_dimension', array_merge(
                $publicRedirect['query'],
                ['dimension' => $dimensionKey->value],
            ));
        }

        if (InsightDimensionKey::IndicationGroups === $dimensionKey) {
            return $this->redirectToRoute('app_stats_insights_dimension', array_merge(
                $request->query->all(),
                ['dimension' => InsightDimensionKey::Indications->value, 'view' => 'groups'],
            ));
        }

        $groupsView = InsightDimensionKey::Indications === $dimensionKey && 'groups' === $request->query->get('view');
        $provider = $groupsView
            ? $this->registry->get(InsightDimensionKey::IndicationGroups)
            : $this->registry->get($dimensionKey);

        $context = $this->statisticsContextFactory->create($user, $filter);
        $search = trim($request->query->getString('q'));
        $sort = $request->query->getString('sort', 'frequency');
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 25);

        $directory = $this->directoryService->list(
            $provider,
            $context,
            $request,
            '' === $search ? null : $search,
            $sort,
            $page,
            $limit,
        );

        $topListKey = $provider->key()->topListKey();
        $topListUrl = null !== $topListKey
            ? $this->navigationUrlBuilder->build($request, 'app_stats_top_lists_show', ['report' => $topListKey])
            : null;
        $navDimension = $groupsView ? InsightDimensionKey::Indications : $dimensionKey;

        return $this->render('@Statistics/insights/dimension.html.twig', array_merge(
            $this->chromeFactory->templateVars($request, 'app_stats_insights_dimension', $user, $filter),
            [
                'insightsSubnav' => $this->subnavFactory->create($request, $navDimension),
                'provider' => $provider,
                'dimensionKey' => $provider->key(),
                'directory' => $directory,
                'searchQuery' => $search,
                'sort' => 'alpha' === $sort ? 'alpha' : 'frequency',
                'groupsView' => $groupsView,
                'showGroupsToggle' => InsightDimensionKey::Indications === $dimensionKey,
                'indicationsUrl' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_insights_dimension',
                    ['dimension' => InsightDimensionKey::Indications->value, 'view' => null],
                    ['q', 'sort', 'page', 'limit'],
                ),
                'groupsUrl' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_insights_dimension',
                    ['dimension' => InsightDimensionKey::Indications->value, 'view' => 'groups'],
                    ['q', 'sort', 'page', 'limit'],
                ),
                'statsIndicationTopListUrl' => $topListUrl,
            ],
        ));
    }
}
