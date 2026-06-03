<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsPeriodResolver;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisConfigResolver;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisService;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisPresetException;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
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

final class GenericAnalysisController extends AbstractController
{
    public function __construct(
        private readonly AnalysisPresetRegistry $presetRegistry,
        private readonly GenericAnalysisService $genericAnalysisService,
        private readonly GenericAnalysisConfigResolver $configResolver,
        private readonly GenericAnalysisPageViewModelFactory $genericAnalysisPageViewModelFactory,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsScopeResolver $statisticsScopeResolver,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly GenericAnalysisTableViewModelFactory $tableViewModelFactory,
        private readonly GenericAnalysisChartViewModelFactory $chartViewModelFactory,
    ) {
    }

    #[Route(
        '/statistics/generic-analysis/{presetKey}',
        name: 'app_stats_generic_analysis',
        methods: ['GET'],
    )]
    public function __invoke(
        string $presetKey,
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_generic_analysis', array_merge(
                ['presetKey' => $presetKey],
                $publicRedirect['query'],
            ));
        }

        try {
            $this->presetRegistry->get($presetKey);
        } catch (UnknownAnalysisPresetException $e) {
            throw new NotFoundHttpException($e->getMessage(), $e);
        }

        $matchingPresetRedirect = $this->maybeMatchingPresetRedirect($request);
        if (null !== $matchingPresetRedirect) {
            return $this->redirect($matchingPresetRedirect);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $scopeCriteria = $this->statisticsScopeResolver->resolveCriteria($context);
        $periodBounds = StatisticsPeriodResolver::resolve($filter);

        try {
            $config = $this->configResolver->resolve($presetKey, $request, $scopeCriteria, $periodBounds, $filter, $user);
        } catch (UnknownAnalysisDimensionException $e) {
            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $result = $this->genericAnalysisService->run($config->displayTitle, $config->query);

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_generic_analysis',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_generic_analysis',
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }

        return $this->render('@Statistics/generic_analysis/show.html.twig', [
            'presetKey' => $presetKey,
            'analysisResult' => $result,
            'genericAnalysisTable' => $this->tableViewModelFactory->create($request, $presetKey, $result),
            'genericAnalysisChart' => $this->chartViewModelFactory->create($config->query, $result),
            'genericAnalysisPage' => $this->genericAnalysisPageViewModelFactory->create(
                $request,
                $presetKey,
                $config,
                $filter,
                $user,
            ),
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
        ]);
    }

    private function maybeMatchingPresetRedirect(Request $request): ?string
    {
        if (!$request->query->has(GenericAnalysisQueryKeys::PRIMARY)) {
            return null;
        }

        $primary = $request->query->get(GenericAnalysisQueryKeys::PRIMARY);
        if (!\is_string($primary) || '' === $primary) {
            return null;
        }

        $series = null;
        if ($request->query->has(GenericAnalysisQueryKeys::SERIES)) {
            $rawSeries = $request->query->get(GenericAnalysisQueryKeys::SERIES);
            $series = \is_string($rawSeries) && '' !== $rawSeries ? $rawSeries : null;
        }

        $includeNull = $request->query->has(GenericAnalysisQueryKeys::INCLUDE_NULL)
            && '1' === (string) $request->query->get(GenericAnalysisQueryKeys::INCLUDE_NULL);

        $matching = $this->configResolver->findMatchingSelectablePreset($primary, $series, $includeNull);
        if (!$matching instanceof \App\Statistics\GenericAnalysis\Domain\DTO\AnalysisPreset) {
            return null;
        }

        return $this->genericAnalysisPageViewModelFactory->buildPresetRedirectUrl($request, $matching->key);
    }
}
