<?php

declare(strict_types=1);

namespace App\Statistics\Benchmarking\UI\Http\Controller;

use App\Statistics\Application\ComparisonScopeResolver;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Benchmarking\Application\BenchmarkCriteriaFactory;
use App\Statistics\Benchmarking\Application\BenchmarkDefaultResolver;
use App\Statistics\Benchmarking\Application\BenchmarkReportService;
use App\Statistics\UI\Http\Controller\StatisticsFilterDrawerStateFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
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
        private readonly StatisticsFilterDrawerStateFactory $statisticsFilterDrawerStateFactory,
        private readonly BenchmarkSelectionViewModelFactory $benchmarkSelectionViewModelFactory,
        private readonly BenchmarkChartPayloadFactory $benchmarkChartPayloadFactory,
        private readonly BenchmarkIndicationMixViewModelFactory $benchmarkIndicationMixViewModelFactory,
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

        $comparisonFilter = $this->comparisonScopeResolver->resolve($request, $user, $filter);
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
        $drawerState = $this->statisticsFilterDrawerStateFactory->fromRequest($request);

        return $this->render('@Statistics/benchmarking/index.html.twig', [
            'report' => $report,
            'chartPayload' => $this->benchmarkChartPayloadFactory->create($report),
            'selection' => $selection,
            'indicationMixViewModel' => $this->benchmarkIndicationMixViewModelFactory->create($request, $report->indicationMix),
            'statsFilterDrawerValues' => $drawerState['values'],
            'statsActiveFilterCount' => $drawerState['activeCount'],
            'statsFilterDrawerResetUrl' => $this->generateUrl('app_stats_benchmarking'),
        ]);
    }
}
