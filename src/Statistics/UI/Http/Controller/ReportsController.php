<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\DTO\StatisticsContext;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Report\ReportDefinitionRegistry;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ReportsController extends AbstractController
{
    use StatisticsPagePresentationTrait;

    private const array ALLOWED_LIMITS = [10, 25, 50];

    public function __construct(
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly HospitalRepository $hospitalRepository,
        private readonly ReportDefinitionRegistry $reportDefinitionRegistry,
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

    #[Route('/statistics/reports', name: 'app_stats_reports', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        $filter = $this->statisticsFilterFactory->createFromRequest(
            $request,
            $user instanceof User ? $user : null,
        );
        $context = new StatisticsContext(
            $user instanceof User ? $user : null,
            $filter,
        );

        $now = new \DateTimeImmutable();
        $defaultYear = $filter->referenceYear ?? (int) $now->format('Y');
        $defaultMonth = $filter->referenceMonth ?? (int) $now->format('n');

        $scopeUrls = [
            'public' => $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['scope' => StatisticsFilterScope::Public->value],
                ['hospital'],
            ),
            'my_hospitals' => $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
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
                    'app_stats_reports',
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
                'app_stats_reports',
                ['period' => StatisticsFilterPeriod::All->value],
                ['year', 'month'],
            ),
            'all_time' => $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['period' => StatisticsFilterPeriod::AllTime->value],
                ['year', 'month'],
            ),
            'year' => $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                [
                    'period' => StatisticsFilterPeriod::Year->value,
                    'year' => $defaultYear,
                ],
                ['month'],
            ),
            'month' => $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
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

        $requestedReport = $request->query->getString('report', '');
        $definition = $this->reportDefinitionRegistry->getOrFirst($requestedReport);
        $reportKey = $definition->key();

        $limit = $this->resolveReportLimit($request->query->all()['limit'] ?? null);

        $reportWidget = $definition->build($context, $limit);

        $reportSelectUrls = [];
        foreach ($this->reportDefinitionRegistry->all() as $item) {
            $reportSelectUrls[$item->key()] = $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['report' => $item->key()],
                [],
            );
        }

        $limitUrls = [];
        foreach (self::ALLOWED_LIMITS as $lim) {
            $limitUrls[$lim] = $this->statisticsPageUrl(
                $request,
                'app_stats_reports',
                ['limit' => $lim],
                [],
            );
        }

        $reportWidget = $this->withReportTableLimitFooter($reportWidget, $limitUrls, $limit);

        $locale = $request->getLocale();

        return $this->render('@Statistics/reports/index.html.twig', [
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
            'reportWidget' => $reportWidget,
            'reportDefinitions' => $this->reportDefinitionRegistry->all(),
            'currentReportKey' => $reportKey,
            'reportSelectUrls' => $reportSelectUrls,
        ]);
    }

    /**
     * @param array<int, string> $limitUrls
     */
    private function withReportTableLimitFooter(StatisticWidget $widget, array $limitUrls, int $currentLimit): StatisticWidget
    {
        if (StatisticWidgetType::Table !== $widget->type) {
            return $widget;
        }

        $payload = $widget->payload;
        $payload['limitFooter'] = [
            'urls' => $limitUrls,
            'current' => $currentLimit,
        ];

        return new StatisticWidget($widget->type, $widget->id, $payload, $widget->title, $widget->actions);
    }

    private function resolveReportLimit(mixed $rawLimit): int
    {
        if (null === $rawLimit || '' === (string) $rawLimit) {
            return 25;
        }

        $parsed = filter_var((string) $rawLimit, FILTER_VALIDATE_INT);
        if (false !== $parsed && \in_array($parsed, self::ALLOWED_LIMITS, true)) {
            return $parsed;
        }

        return 25;
    }
}
