<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Report\ReportDefinitionRegistry;
use App\Statistics\Application\StatisticsContextFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class ReportsController extends AbstractController
{
    public function __construct(
        private readonly StatisticsContextFactory $statisticsContextFactory,
        private readonly ReportsRequestModelFactory $reportsRequestModelFactory,
        private readonly ReportDefinitionRegistry $reportDefinitionRegistry,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly ReportsPagePresenter $reportsPagePresenter,
    ) {
    }

    #[Route('/statistics/reports', name: 'app_stats_reports', methods: ['GET'])]
    public function __invoke(
        Request $request,
        #[CurrentUser] ?User $user,
        #[ValueResolver(StatisticsFilterValueResolver::class)] StatisticsFilter $filter,
    ): Response {
        if ($filter->requiresPublicRedirect) {
            if (null !== $filter->notice) {
                $this->addFlash('error', $filter->notice->value);
            }
            $query = $request->query->all();
            $query['scope'] = StatisticsFilterScope::Public->value;
            unset($query['cohort'], $query['hospital']);

            return $this->redirectToRoute('app_stats_reports', $query);
        }

        $context = $this->statisticsContextFactory->create($user, $filter);
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_reports',
            $user,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }

        $reportsRequest = $this->reportsRequestModelFactory->fromQuery($request->query->all());
        $definition = $this->reportDefinitionRegistry->getOrFirst($reportsRequest->reportKey);
        $reportWidget = $definition->build($context, $reportsRequest->limit);
        $reportsPage = $this->reportsPagePresenter->present(
            $request,
            $definition,
            $reportsRequest,
            $reportWidget,
            $this->reportDefinitionRegistry->all(),
        );

        return $this->render('@Statistics/reports/index.html.twig', [
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
            'statsPeriodUrls' => $pageViewModel->periodUrls,
            'accessibleHospitals' => $pageViewModel->accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $pageViewModel->hospitalDropdownSelectedName,
            'isLoggedIn' => $pageViewModel->isLoggedIn,
            'statisticsHeadingScope' => $pageViewModel->headingScope,
            'statisticsHeadingPeriod' => $pageViewModel->headingPeriod,
            'reportsPage' => $reportsPage,
        ]);
    }
}
