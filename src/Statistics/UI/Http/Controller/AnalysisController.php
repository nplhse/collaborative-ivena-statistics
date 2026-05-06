<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionRegistry;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\StatisticsFilterFactory;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AnalysisController extends AbstractController
{
    public function __construct(
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly AnalysisRequestModelFactory $analysisRequestModelFactory,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly AnalysisPagePresenter $analysisPagePresenter,
        private readonly AnalysisDefinitionRegistry $analysisDefinitionRegistry,
    ) {
    }

    #[Route('/statistics/analysis', name: 'app_stats_analysis', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $filter = $this->statisticsFilterFactory->createFromRequest(
            $request,
            $user instanceof User ? $user : null,
        );
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_analysis',
            $user instanceof User ? $user : null,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }
        $analysisRequest = $this->analysisRequestModelFactory->fromRequest($request);
        $definition = $this->analysisDefinitionRegistry->getOrFirst($analysisRequest->analysisKey);
        $analysisKey = $definition->key();

        $context = new StatisticsContext(
            $user instanceof User ? $user : null,
            $filter,
            null,
            $analysisRequest->rows,
            $analysisRequest->cols,
            $analysisRequest->measure,
        );

        $analysisWidget = $definition->build(
            $context,
            $analysisRequest->view,
            $analysisRequest->chartType,
            $analysisRequest->dimension,
            $analysisRequest->chartMeasure,
        );
        $analysisPage = $this->analysisPagePresenter->present(
            $request,
            $analysisRequest,
            $analysisKey,
            $analysisWidget,
            $this->analysisDefinitionRegistry->all(),
        );

        return $this->render('@Statistics/analysis/index.html.twig', [
            'statisticsFilter' => $pageViewModel->filter,
            'statsScopeUrls' => $pageViewModel->scopeUrls,
            'statsHospitalUrls' => $pageViewModel->hospitalUrls,
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
