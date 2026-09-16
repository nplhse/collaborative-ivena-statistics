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

final class InsightsOverviewController extends AbstractController
{
    private const int FEATURED_TOP_LIMIT = 25;

    private const int TEASER_TOP_LIMIT = 5;

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

    #[Route('/statistics/insights', name: 'app_stats_insights', methods: ['GET'])]
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

            return $this->redirectToRoute('app_stats_insights', $publicRedirect['query']);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $featured = $this->registry->featured();
        $groupsProvider = $this->registry->get(InsightDimensionKey::IndicationGroups);
        $featuredRows = $this->directoryService->topValues($featured, $context, $request, self::FEATURED_TOP_LIMIT);
        $groupRows = $this->directoryService->topValues($groupsProvider, $context, $request, self::FEATURED_TOP_LIMIT);

        $teasers = [];
        foreach ($this->registry->overviewTeasers() as $provider) {
            $teasers[] = [
                'key' => $provider->key()->value,
                'labelKey' => $provider->labelTranslationKey(),
                'icon' => $provider->icon(),
                'url' => $this->navigationUrlBuilder->build(
                    $request,
                    'app_stats_insights_dimension',
                    ['dimension' => $provider->key()->value],
                    ['q', 'sort', 'page', 'limit', 'view'],
                ),
                'rows' => $this->directoryService->topValues($provider, $context, $request, self::TEASER_TOP_LIMIT),
            ];
        }

        $searchUrl = $this->navigationUrlBuilder->build($request, 'app_stats_insights_search');

        return $this->render('@Statistics/insights/overview.html.twig', array_merge(
            $this->chromeFactory->templateVars($request, 'app_stats_insights', $user, $filter),
            [
                'insightsSubnav' => $this->subnavFactory->create($request, null),
                'featured' => [
                    'key' => $featured->key()->value,
                    'labelKey' => $featured->labelTranslationKey(),
                    'allLinkKey' => $featured->allValuesLinkTranslationKey(),
                    'url' => $this->navigationUrlBuilder->build(
                        $request,
                        'app_stats_insights_dimension',
                        ['dimension' => $featured->key()->value],
                        ['q', 'sort', 'page', 'limit', 'view'],
                    ),
                    'rows' => $featuredRows,
                    'topListUrl' => $this->navigationUrlBuilder->build(
                        $request,
                        'app_stats_top_lists_show',
                        ['report' => 'top_diagnoses'],
                    ),
                    'groups' => [
                        'labelKey' => $groupsProvider->labelTranslationKey(),
                        'allLinkKey' => $groupsProvider->allValuesLinkTranslationKey(),
                        'url' => $this->navigationUrlBuilder->build(
                            $request,
                            'app_stats_insights_dimension',
                            ['dimension' => $featured->key()->value, 'view' => 'groups'],
                            ['q', 'sort', 'page', 'limit'],
                        ),
                        'rows' => $groupRows,
                    ],
                ],
                'teasers' => $teasers,
                'searchUrl' => $searchUrl,
            ],
        ));
    }
}
