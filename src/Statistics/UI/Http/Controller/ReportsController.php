<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\DTO\StatisticsContext;
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

final class ReportsController extends AbstractController
{
    private const array ALLOWED_LIMITS = [10, 25, 50];

    public function __construct(
        private readonly StatisticsFilterFactory $statisticsFilterFactory,
        private readonly ReportDefinitionRegistry $reportDefinitionRegistry,
        private readonly StatisticsPageViewModelFactory $statisticsPageViewModelFactory,
        private readonly StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
    ) {
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
        $pageViewModel = $this->statisticsPageViewModelFactory->create(
            $request,
            'app_stats_reports',
            $user instanceof User ? $user : null,
            $filter,
        );

        if ($pageViewModel->showUnscopedHint) {
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
                $request, 'app_stats_reports', ['report' => $item->key()],
            );
        }

        $limitUrls = [];
        foreach (self::ALLOWED_LIMITS as $lim) {
            $limitUrls[$lim] = $this->statisticsPageUrl(
                $request, 'app_stats_reports', ['limit' => $lim],
            );
        }

        $reportWidget = $this->withReportTableLimitFooter($reportWidget, $limitUrls, $limit);

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
