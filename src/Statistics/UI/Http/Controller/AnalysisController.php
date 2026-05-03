<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\Analysis\AnalysisDefinitionRegistry;
use App\Statistics\Application\DTO\PivotColAxis;
use App\Statistics\Application\DTO\PivotRowAxis;
use App\Statistics\Application\DTO\PivotTableAxes;
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

        $requestedAnalysis = $request->query->getString('analysis', '');
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

        $pivotAxes = PivotTableAxes::fromQuery(
            $request->query->getString('rows'),
            $request->query->getString('cols'),
        );
        $context = new StatisticsContext(
            $user instanceof User ? $user : null,
            $filter,
            'pivot' === $analysisKey ? $pivotAxes : null,
        );

        $analysisWidget = $definition->build($context, $view, $chartType, $dimension, $chartMeasure);

        $analysisSelectUrls = [];
        foreach ($this->analysisDefinitionRegistry->all() as $item) {
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
        /** @var list<array{labelKey: string, url: string, active: bool}> $pivotRowChoices */
        $pivotRowChoices = [];
        foreach ([PivotRowAxis::Department, PivotRowAxis::AgeGroup, PivotRowAxis::Urgency] as $rowAxis) {
            $canonical = match ($rowAxis) {
                PivotRowAxis::Department => new PivotTableAxes(PivotRowAxis::Department, PivotColAxis::Urgency),
                PivotRowAxis::AgeGroup => new PivotTableAxes(PivotRowAxis::AgeGroup, PivotColAxis::Gender),
                PivotRowAxis::Urgency => new PivotTableAxes(PivotRowAxis::Urgency, PivotColAxis::Gender),
            };
            $pivotRowChoices[] = [
                'labelKey' => match ($rowAxis) {
                    PivotRowAxis::Department => 'stats.analysis.pivot.axis.rows.department',
                    PivotRowAxis::AgeGroup => 'stats.analysis.pivot.axis.rows.age_group',
                    PivotRowAxis::Urgency => 'stats.analysis.pivot.axis.rows.urgency',
                },
                'url' => $this->statisticsPageUrl(
                    $request,
                    'app_stats_analysis',
                    [
                        'analysis' => 'pivot',
                        'rows' => $canonical->row->value,
                        'cols' => $canonical->col->value,
                        'view' => 'table',
                    ],
                    $pivotStaleQueryKeys,
                ),
                'active' => $pivotAxes->row === $rowAxis,
            ];
        }

        /** @var list<array{labelKey: string, url: string, active: bool}> $pivotColChoices */
        $pivotColChoices = [];
        foreach ([PivotColAxis::Gender, PivotColAxis::Urgency] as $colAxis) {
            $canonical = match ($colAxis) {
                PivotColAxis::Gender => new PivotTableAxes(PivotRowAxis::Urgency, PivotColAxis::Gender),
                PivotColAxis::Urgency => new PivotTableAxes(PivotRowAxis::Department, PivotColAxis::Urgency),
            };
            $pivotColChoices[] = [
                'labelKey' => match ($colAxis) {
                    PivotColAxis::Gender => 'stats.analysis.pivot.axis.cols.gender',
                    PivotColAxis::Urgency => 'stats.analysis.pivot.axis.cols.urgency',
                },
                'url' => $this->statisticsPageUrl(
                    $request,
                    'app_stats_analysis',
                    [
                        'analysis' => 'pivot',
                        'rows' => $canonical->row->value,
                        'cols' => $canonical->col->value,
                        'view' => 'table',
                    ],
                    $pivotStaleQueryKeys,
                ),
                'active' => $pivotAxes->col === $colAxis,
            ];
        }

        if (StatisticWidgetType::PivotTable === $analysisWidget->type) {
            $analysisWidget = new StatisticWidget(
                StatisticWidgetType::PivotTable,
                $analysisWidget->id,
                array_merge($analysisWidget->payload, [
                    'pivotRowChoices' => $pivotRowChoices,
                    'pivotColChoices' => $pivotColChoices,
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
            'analysisDefinitions' => $this->analysisDefinitionRegistry->all(),
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
