<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
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
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AnalysisController extends AbstractController
{
    use StatisticsPagePresentationTrait;

    public function __construct(
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly HospitalRepository $hospitalRepository,
        private readonly AnalysisDefinitionRegistry $analysisDefinitionRegistry,
        private readonly TranslatorInterface $translator,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
    }

    #[\Override]
    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    #[\Override]
    protected function getStatisticsNavigationUrlBuilder(): StatisticsNavigationUrlBuilder
    {
        return $this->statisticsNavigationUrlBuilder;
    }

    #[Route('/statistics/analysis', name: 'app_stats_analysis', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $filter = $this->statisticsFilterFactory->createFromRequest(
            $request,
            $user instanceof User ? $user : null,
        );
        $now = new \DateTimeImmutable();
        $defaultYear = $filter->referenceYear ?? (int) $now->format('Y');
        $defaultMonth = $filter->referenceMonth ?? (int) $now->format('n');

        $scopeUrls = [
            'public' => $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                ['scope' => StatisticsFilterScope::Public->value],
                ['hospital'],
            ),
            'my_hospitals' => $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                ['scope' => StatisticsFilterScope::MyHospitals->value],
                ['hospital'],
            ),
        ];

        $accessibleHospitals = [];
        $hospitalUrls = [];
        if ($user instanceof User) {
            $accessibleHospitals = $this->hospitalRepository->findAccessibleHospitalSummaries($user);
            foreach ($accessibleHospitals as $row) {
                $hospitalUrls[(string) $row['id']] = $this->statisticsPageUrl(
                    $request,
                    'app_stats_analysis',
                    [
                        'scope' => StatisticsFilterScope::Hospital->value,
                        'hospital' => $row['id'],
                    ],
                    [],
                );
            }
        }

        $periodUrls = [
            'all' => $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                ['period' => StatisticsFilterPeriod::All->value],
                ['year', 'month'],
            ),
            'all_time' => $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                ['period' => StatisticsFilterPeriod::AllTime->value],
                ['year', 'month'],
            ),
            'year' => $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                [
                    'period' => StatisticsFilterPeriod::Year->value,
                    'year' => $defaultYear,
                ],
                ['month'],
            ),
            'month' => $this->statisticsPageUrl(
                $request,
                'app_stats_analysis',
                [
                    'period' => StatisticsFilterPeriod::Month->value,
                    'year' => $defaultYear,
                    'month' => $defaultMonth,
                ],
                [],
            ),
        ];

        $hospitalDropdownSelectedName = null;
        if (StatisticsFilterScope::Hospital === $filter->scope && null !== $filter->hospitalId) {
            foreach ($accessibleHospitals as $row) {
                if ($row['id'] === $filter->hospitalId) {
                    $hospitalDropdownSelectedName = $row['name'];
                    break;
                }
            }
        }

        if ($user instanceof User
            && [] === $accessibleHospitals
            && StatisticsFilterScope::MyHospitals === $filter->scope) {
            $this->addFlash('info', 'stats.overview.hospital_summary.unscoped_hint');
        }

        $requestedAnalysis = $request->query->getString('analysis', 'allocations_by_month');
        if ('pivot' === $requestedAnalysis) {
            $requestedAnalysis = 'allocation_pivot';
        } elseif ('allocations_over_time' === $requestedAnalysis) {
            $requestedAnalysis = 'allocations_by_month';
        }
        $definition = $this->analysisDefinitionRegistry->getOrFirst($requestedAnalysis);
        $analysisKey = $definition->key();

        $view = $request->query->getString('view', 'chart');
        if (!\in_array($view, ['chart', 'table'], true)) {
            $view = 'chart';
        }

        $chartType = $request->query->getString('chart', 'bar');
        if (!\in_array($chartType, ['line', 'bar'], true)) {
            $chartType = 'bar';
        }

        $dimension = StatisticsAnalysisDimension::tryFrom($request->query->getString('dimension'))
            ?? StatisticsAnalysisDimension::Total;

        $chartMeasure = StatisticsChartMeasure::fromQueryValue($request->query->getString('chart_measure'));
        if (StatisticsAnalysisDimension::Features === $dimension && StatisticsChartMeasure::Share === $chartMeasure) {
            $chartMeasure = StatisticsChartMeasure::Absolute;
        }

        $context = new StatisticsContext(
            $user instanceof User ? $user : null,
            $filter,
            null,
            $request->query->getString('rows'),
            $request->query->getString('cols'),
            $request->query->getString('measure'),
        );

        $analysisWidget = $definition->build($context, $view, $chartType, $dimension, $chartMeasure);

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

        $locale = $request->getLocale();

        return $this->render('@Statistics/analysis/index.html.twig', [
            'statisticsFilter' => $filter,
            'statsScopeUrls' => $scopeUrls,
            'statsHospitalUrls' => $hospitalUrls,
            'statsPeriodUrls' => $periodUrls,
            'accessibleHospitals' => $accessibleHospitals,
            'statsHospitalDropdownSelectedName' => $hospitalDropdownSelectedName,
            'isLoggedIn' => $user instanceof User,
            'statisticsHeadingScope' => $this->statisticsHeadingScope(
                $filter,
                $hospitalDropdownSelectedName,
                $locale,
                $user instanceof User && [] === $accessibleHospitals,
                \count($accessibleHospitals),
            ),
            'statisticsHeadingPeriod' => $this->statisticsHeadingPeriod($filter, $locale),
            'analysisWidget' => $analysisWidget,
            'analysisDefinitions' => $analysisDefinitions,
            'currentAnalysisKey' => $analysisKey,
            'analysisSelectUrls' => $analysisSelectUrls,
            'currentView' => $view,
            'currentChartType' => $chartType,
            'viewChartUrl' => $viewChartUrl,
            'viewTableUrl' => $viewTableUrl,
            'chartLineUrl' => $chartLineUrl,
            'chartBarUrl' => $chartBarUrl,
            'currentAnalysisDimension' => $dimension->value,
            'dimensionTotalUrl' => $dimensionTotalUrl,
            'dimensionGenderUrl' => $dimensionGenderUrl,
            'dimensionUrgencyUrl' => $dimensionUrgencyUrl,
            'dimensionResourcesUrl' => $dimensionResourcesUrl,
            'dimensionFeaturesUrl' => $dimensionFeaturesUrl,
            'currentChartMeasure' => $chartMeasure->value,
            'chartMeasureAbsoluteUrl' => $chartMeasureAbsoluteUrl,
            'chartMeasureShareUrl' => $chartMeasureShareUrl,
        ]);
    }
}
