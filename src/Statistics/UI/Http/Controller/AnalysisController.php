<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionRegistry;
use App\Statistics\Application\Pivot\AllocationPivotDimension;
use App\Statistics\Application\Pivot\AllocationPivotMeasure;
use App\Statistics\Application\Pivot\AllocationPivotSelection;
use App\Statistics\Application\Pivot\HospitalPivotDimension;
use App\Statistics\Application\Pivot\HospitalPivotMeasure;
use App\Statistics\Application\Pivot\HospitalPivotSelection;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
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
        private readonly AnalysisDefinitionRegistry $analysisDefinitionRegistry,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
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

        $analysisSelectUrls = [];
        $analysisDefinitions = [];
        foreach ($this->analysisDefinitionRegistry->all() as $item) {
            if ('pivot' === $item->key()) {
                continue;
            }
            $analysisDefinitions[] = $item;
            $analysisSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                ['analysis' => $item->key()],
                [],
            );
        }

        $dimensionTotalUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['dimension' => StatisticsAnalysisDimension::Total->value],
            [],
        );
        $dimensionGenderUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['dimension' => StatisticsAnalysisDimension::Gender->value],
            [],
        );
        $dimensionUrgencyUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['dimension' => StatisticsAnalysisDimension::Urgency->value],
            [],
        );
        $dimensionResourcesUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['dimension' => StatisticsAnalysisDimension::Resources->value],
            [],
        );
        $dimensionFeaturesUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['dimension' => StatisticsAnalysisDimension::Features->value],
            [],
        );

        $viewChartUrl = $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'chart']);
        $viewTableUrl = $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'table']);
        $chartLineUrl = $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'chart', 'chart' => 'line']);
        $chartBarUrl = $this->statisticsPageUrl($request, 'app_stats_analysis', ['view' => 'chart', 'chart' => 'bar']);

        $chartMeasureAbsoluteUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['chart_measure' => StatisticsChartMeasure::Absolute->value],
            [],
        );
        $chartMeasureShareUrl = $this->statisticsPageUrl(
            $request,
            'app_stats_analysis',
            ['chart_measure' => StatisticsChartMeasure::Share->value],
            [],
        );

        $pivotStaleQueryKeys = ['dimension', 'chart_measure', 'chart'];
        $pivotRowChoices = [];
        $pivotColChoices = [];
        $pivotMeasureChoices = [];
        if ('allocation_pivot' === $analysisKey) {
            $selection = AllocationPivotSelection::fromQuery(
                $request->query->getString('rows'),
                $request->query->getString('cols'),
                $request->query->getString('measure'),
            );
            foreach (AllocationPivotDimension::cases() as $axis) {
                $pivotRowChoices[] = [
                    'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'allocation_pivot',
                        'rows' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->rows === $axis,
                ];
                $pivotColChoices[] = [
                    'labelKey' => 'stats.analysis.pivot.axis.rows.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'allocation_pivot',
                        'cols' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->cols === $axis,
                ];
            }
            foreach (AllocationPivotMeasure::cases() as $measure) {
                $pivotMeasureChoices[] = [
                    'labelKey' => 'stats.analysis.allocation_pivot.measure.'.$measure->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'allocation_pivot',
                        'measure' => $measure->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->measure === $measure,
                ];
            }
        } elseif ('hospital_pivot' === $analysisKey) {
            $selection = HospitalPivotSelection::fromQuery(
                $request->query->getString('rows'),
                $request->query->getString('cols'),
                $request->query->getString('measure'),
            );
            foreach (HospitalPivotDimension::cases() as $axis) {
                $pivotRowChoices[] = [
                    'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'hospital_pivot',
                        'rows' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->rows === $axis,
                ];
                $pivotColChoices[] = [
                    'labelKey' => 'stats.analysis.hospital_pivot.axis.'.$axis->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'hospital_pivot',
                        'cols' => $axis->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->cols === $axis,
                ];
            }
            foreach (HospitalPivotMeasure::cases() as $measure) {
                $pivotMeasureChoices[] = [
                    'labelKey' => 'stats.analysis.hospital_pivot.measure.'.$measure->value,
                    'url' => $this->statisticsPageUrl($request, 'app_stats_analysis', [
                        'analysis' => 'hospital_pivot',
                        'measure' => $measure->value,
                        'view' => 'table',
                    ], $pivotStaleQueryKeys),
                    'active' => $selection->measure === $measure,
                ];
            }
        }

        if (StatisticWidgetType::PivotTable === $analysisWidget->type) {
            $analysisWidget = new StatisticWidget(
                StatisticWidgetType::PivotTable,
                $analysisWidget->id,
                array_merge($analysisWidget->payload, [
                    'pivotRowChoices' => $pivotRowChoices,
                    'pivotColChoices' => $pivotColChoices,
                    'pivotMeasureChoices' => $pivotMeasureChoices,
                ]),
                $analysisWidget->title,
                $analysisWidget->actions,
            );
        }

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
            'analysisWidget' => $analysisWidget,
            'analysisDefinitions' => $analysisDefinitions,
            'currentAnalysisKey' => $analysisKey,
            'analysisSelectUrls' => $analysisSelectUrls,
            'currentView' => $analysisRequest->view,
            'currentChartType' => $analysisRequest->chartType,
            'viewChartUrl' => $viewChartUrl,
            'viewTableUrl' => $viewTableUrl,
            'chartLineUrl' => $chartLineUrl,
            'chartBarUrl' => $chartBarUrl,
            'currentAnalysisDimension' => $analysisRequest->dimension->value,
            'dimensionTotalUrl' => $dimensionTotalUrl,
            'dimensionGenderUrl' => $dimensionGenderUrl,
            'dimensionUrgencyUrl' => $dimensionUrgencyUrl,
            'dimensionResourcesUrl' => $dimensionResourcesUrl,
            'dimensionFeaturesUrl' => $dimensionFeaturesUrl,
            'currentChartMeasure' => $analysisRequest->chartMeasure->value,
            'chartMeasureAbsoluteUrl' => $chartMeasureAbsoluteUrl,
            'chartMeasureShareUrl' => $chartMeasureShareUrl,
        ]);
    }

    /**
     * @param array<string, scalar|null> $replace
     * @param list<string>               $removeKeys
     */
    private function statisticsPageUrl(
        Request $request,
        string $routeName,
        array $replace = [],
        array $removeKeys = [],
    ): string {
        return $this->statisticsNavigationUrlBuilder->build($request, $routeName, $replace, $removeKeys);
    }
}
