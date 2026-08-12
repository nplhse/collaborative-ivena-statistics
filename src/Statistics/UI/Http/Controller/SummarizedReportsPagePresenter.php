<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Statistics\Application\Contract\ProjectionEarliestDateProviderInterface;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use App\Statistics\Application\SummarizedReport\ReportBuildResult;
use App\Statistics\Application\SummarizedReport\ReportTypeInterface;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Component\HttpFoundation\Request;

final readonly class SummarizedReportsPagePresenter
{
    private const string TIMEZONE = 'Europe/Berlin';

    public function __construct(
        private StatisticsNavigationUrlBuilder $statisticsNavigationUrlBuilder,
        private ProjectionEarliestDateProviderInterface $earliestDateProvider,
    ) {
    }

    public function present(
        Request $request,
        ReportTypeInterface $currentType,
        ReportBuildResult $buildResult,
    ): SummarizedReportsPageViewModel {
        $periodLabel = '';
        $periodNavigation = null;
        if ($buildResult->viewModel instanceof MonthlyReportView) {
            $periodLabel = $buildResult->viewModel->periodLabel;
            $periodNavigation = $this->monthPeriodControls($request, $buildResult->viewModel);
        }

        return new SummarizedReportsPageViewModel(
            $buildResult,
            $currentType->key(),
            $currentType->labelTranslationKey(),
            $currentType->descriptionTranslationKey(),
            $periodLabel,
            $this->statisticsNavigationUrlBuilder->build(
                $request,
                'app_stats_reports',
                removeKeys: ['type', 'year', 'month'],
            ),
            $periodNavigation,
        );
    }

    private function monthPeriodControls(Request $request, MonthlyReportView $report): OverviewPeriodViewModel
    {
        $locale = $request->getLocale();
        $tz = new \DateTimeZone(self::TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);
        $latestCompleted = $now->modify('first day of this month 00:00:00')->modify('-1 month');
        $earliestYear = $this->earliestYear($latestCompleted);

        $yearMenu = [];
        for ($year = (int) $latestCompleted->format('Y'); $year >= $earliestYear; --$year) {
            $monthForYear = $this->defaultMonthForYear($year, $report->month, $latestCompleted);
            $yearMenu[] = [
                'key' => (string) $year,
                'label' => (string) $year,
                'url' => $this->monthUrl($request, $year, $monthForYear),
                'active' => $report->year === $year,
            ];
        }

        $monthMenu = [];
        $maxMonth = $report->year === (int) $latestCompleted->format('Y')
            ? (int) $latestCompleted->format('n')
            : 12;
        for ($month = 1; $month <= $maxMonth; ++$month) {
            $monthMenu[] = [
                'label' => $this->formatMonthName($report->year, $month, $locale),
                'url' => $this->monthUrl($request, $report->year, $month),
                'active' => $report->month === $month,
            ];
        }

        $previousUrl = $this->monthUrl($request, $report->previousYear, $report->previousMonth);
        $nextUrl = null;
        if ($report->nextEnabled && null !== $report->nextYear && null !== $report->nextMonth) {
            $nextUrl = $this->monthUrl($request, $report->nextYear, $report->nextMonth);
        }

        return new OverviewPeriodViewModel(
            $report->periodLabel,
            (string) $report->year,
            $this->formatMonthName($report->year, $report->month, $locale),
            true,
            $yearMenu,
            $monthMenu,
            $previousUrl,
            $nextUrl,
            $report->previousMonthLabel,
            $report->nextMonthLabel,
            true,
            $report->nextEnabled,
            true,
        );
    }

    private function monthUrl(Request $request, int $year, int $month): string
    {
        return $this->statisticsNavigationUrlBuilder->build(
            $request,
            'app_stats_reports_show',
            [
                'year' => $year,
                'month' => $month,
            ],
        );
    }

    private function defaultMonthForYear(int $year, int $preferredMonth, \DateTimeImmutable $latestCompleted): int
    {
        $maxMonth = $year === (int) $latestCompleted->format('Y')
            ? (int) $latestCompleted->format('n')
            : 12;

        return max(1, min($preferredMonth, $maxMonth));
    }

    private function earliestYear(\DateTimeImmutable $latestCompleted): int
    {
        $earliest = $this->earliestDateProvider->getEarliestCreatedAt();
        if ($earliest instanceof \DateTimeImmutable) {
            return (int) $earliest->format('Y');
        }

        return (int) $latestCompleted->format('Y');
    }

    private function formatMonthName(int $year, int $month, string $locale): string
    {
        $date = new \DateTimeImmutable(sprintf('%04d-%02d-15', $year, $month));
        $formatted = \IntlDateFormatter::formatObject($date, 'LLLL', $locale);

        return false !== $formatted ? $formatted : sprintf('%02d', $month);
    }
}
