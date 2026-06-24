<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkDefaultResolver;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterDrawerViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\Statistics\UI\Http\Navigation\StatisticsQueryParamNormalizer;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class BenchmarkingController extends AbstractController
{
    public function __construct(
        private readonly BenchmarkDefaultResolver $benchmarkDefaultResolver,
        private readonly BenchmarkReportService $benchmarkReportService,
        private readonly BenchmarkCriteriaFactory $benchmarkCriteriaFactory,
        private readonly ComparisonScopeResolver $comparisonScopeResolver,
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsFilterDrawerViewModelFactory $statisticsFilterDrawerViewModelFactory,
        private readonly BenchmarkSelectionViewModelFactory $benchmarkSelectionViewModelFactory,
        private readonly BenchmarkChartPayloadFactory $benchmarkChartPayloadFactory,
        private readonly BenchmarkIndicationMixViewModelFactory $benchmarkIndicationMixViewModelFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
        private readonly BenchmarkSelectionFormDataFactory $benchmarkSelectionFormDataFactory,
    ) {
    }

    #[Route('/statistics/benchmarking', name: 'app_stats_benchmarking', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        $defaultRedirect = $this->benchmarkDefaultResolver->maybeRedirectPayload($request, $user);
        if (null !== $defaultRedirect) {
            return $this->redirectToRoute('app_stats_benchmarking', $defaultRedirect['query']);
        }

        $publicRedirect = $this->publicScopeRedirector->maybeRedirectPayload($request, $filter);
        if (null !== $publicRedirect) {
            if (null !== $publicRedirect['notice']) {
                $this->addFlash('error', $publicRedirect['notice']->value);
            }

            return $this->redirectToRoute('app_stats_benchmarking', $publicRedirect['query']);
        }

        $comparisonFilter = $this->comparisonScopeResolver->resolve($request, $user, $filter, HospitalPermission::Benchmarking);
        $context = $this->statisticsContextFactory->create(
            $user,
            $filter,
            comparisonFilter: $comparisonFilter,
        );
        $criteria = $this->benchmarkCriteriaFactory->create($context, $comparisonFilter);
        $report = $this->benchmarkReportService->build($criteria);

        $selection = $this->benchmarkSelectionViewModelFactory->create(
            $request,
            $user,
            $filter,
            $comparisonFilter,
        );
        $statsFilterDrawer = $this->statisticsFilterDrawerViewModelFactory->create($request);
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_benchmarking',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_benchmarking',
            $filter,
        );
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/benchmarking/index.html.twig', [
            'dataQualityReport' => $dataQualityReport,
            'report' => $report,
            'chartPayload' => $this->benchmarkChartPayloadFactory->create($report),
            'selection' => $selection,
            'benchmarkSelectionFormData' => $this->benchmarkSelectionFormDataFactory->fromFilters($filter, $comparisonFilter),
            'benchmarkSelectionPreservedQuery' => $this->extractPreservedSelectionQuery($request),
            'indicationMixViewModel' => $this->benchmarkIndicationMixViewModelFactory->create($request, $report->indicationMix),
            'statsFilterDrawer' => $statsFilterDrawer,
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_benchmarking'),
        ]);
    }

    /**
     * @return array<string, bool|float|int|string>
     */
    private function extractPreservedSelectionQuery(Request $request): array
    {
        $routeParams = $request->attributes->get('_route_params', []);
        if (!\is_array($routeParams)) {
            $routeParams = [];
        }

        $query = StatisticsQueryParamNormalizer::normalize(array_merge($routeParams, $request->query->all()));
        foreach (BenchmarkSelectionQueryBuilder::SELECTION_QUERY_KEYS as $key) {
            unset($query[$key]);
        }

        return $query;
    }
}
