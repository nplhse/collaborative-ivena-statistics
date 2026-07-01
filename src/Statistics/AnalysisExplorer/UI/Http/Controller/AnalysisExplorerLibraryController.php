<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\UI\Http\Controller\OverviewPeriodViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsDataQualityReportFactory;
use App\Statistics\UI\Http\Controller\StatisticsFilterValueResolver;
use App\Statistics\UI\Http\Controller\StatisticsPageViewModelFactory;
use App\Statistics\UI\Http\Controller\StatisticsPublicScopeRedirector;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Translation\TranslatableMessage;

final class AnalysisExplorerLibraryController extends AbstractController
{
    public function __construct(
        private readonly AnalysisExplorerLibraryPageViewModelFactory $pageViewModelFactory,
        private readonly StatisticsPublicScopeRedirector $publicScopeRedirector,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly OverviewPeriodViewModelFactory $overviewPeriodViewModelFactory,
        private readonly StatisticsDataQualityReportFactory $dataQualityReportFactory,
    ) {
    }

    #[Route('/statistics/analysis/library', name: 'app_stats_analysis_library', methods: ['GET'])]
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

            return $this->redirectToRoute('app_stats_analysis_library', $publicRedirect['query']);
        }

        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_analysis_library',
            $user,
            $filter,
        );
        $overviewPeriodViewModel = $this->overviewPeriodViewModelFactory->create(
            $request,
            'app_stats_analysis_library',
            $filter,
        );
        $dataQualityReport = $this->dataQualityReportFactory->create(
            $filter,
            $user,
            $pageViewModel,
            $overviewPeriodViewModel,
        );

        return $this->render('@Statistics/analysis_explorer_library/library.html.twig', [
            'dataQualityReport' => $dataQualityReport,
            'statisticsFilter' => $pageViewModel->filter,
            'explorerLibraryPage' => $this->pageViewModelFactory->create($request, $user),
            'isLoggedIn' => $pageViewModel->isLoggedIn,
        ]);
    }
}
