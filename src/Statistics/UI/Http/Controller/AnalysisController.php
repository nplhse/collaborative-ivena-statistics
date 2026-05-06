<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionRegistry;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\StatisticsContextFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly AnalysisRequestModelFactory $analysisRequestModelFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly AnalysisPagePresenter $analysisPagePresenter,
        private readonly AnalysisDefinitionRegistry $analysisDefinitionRegistry,
        private readonly ComparisonScopeResolver $comparisonScopeResolver,
    ) {
    }

    #[Route('/statistics/analysis', name: 'app_stats_analysis', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if ($filter->requiresPublicRedirect) {
            if ($filter->notice instanceof \App\Statistics\Application\DTO\StatisticsFilterNotice) {
                $this->addFlash('error', $filter->notice->value);
            }
            $query = $request->query->all();
            $query['scope'] = StatisticsFilterScope::Public->value;
            unset($query['cohort'], $query['hospital']);

            return $this->redirectToRoute('app_stats_analysis', $query);
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_analysis',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }
        $analysisRequest = $this->analysisRequestModelFactory->fromRequest($request);
        $comparisonFilter = $this->comparisonScopeResolver->resolve($request, $user, $filter);
        if (!$request->query->has('comparison_scope') && $comparisonFilter->cohortType instanceof \App\Statistics\Application\Cohort\HospitalCohortType) {
            $query = $request->query->all();
            $query['comparison_scope'] = StatisticsFilterScope::HospitalCohort->value.':'.$comparisonFilter->cohortType->value;

            return $this->redirectToRoute('app_stats_analysis', $query);
        }
        if ('allocations_comparison_over_time' === $analysisRequest->analysisKey && !$request->query->has('comparison_period')) {
            $query = $request->query->all();
            $query['comparison_period'] = $comparisonFilter->period->value;
            if (null !== $comparisonFilter->referenceYear) {
                $query['comparison_year'] = $comparisonFilter->referenceYear;
            }
            if (null !== $comparisonFilter->referenceMonth) {
                $query['comparison_month'] = $comparisonFilter->referenceMonth;
            }

            return $this->redirectToRoute('app_stats_analysis', $query);
        }
        $view = 'table' === $analysisRequest->view ? 'table' : 'chart';
        $chartType = 'line' === $analysisRequest->chartType ? 'line' : 'bar';
        $definition = $this->analysisDefinitionRegistry->getOrFirst($analysisRequest->analysisKey);
        $analysisKey = $definition->key();

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
            $view,
            $chartType,
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

        return $this->render('@Statistics/analysis/index.html.twig', [
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
            'cohortScopeChoices' => $pageViewModel->cohortScopeChoices,
            'statsCohortDropdownSelectedName' => $pageViewModel->cohortDropdownSelectedName,
            'statsPeriodUrls' => $pageViewModel->periodUrls,
            'accessibleHospitals' => $pageViewModel->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $pageViewModel->hospitalDropdownSelectedName,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $pageViewModel->headingPeriod,
            'analysisPage' => $analysisPage,
        ]);
    }
}
