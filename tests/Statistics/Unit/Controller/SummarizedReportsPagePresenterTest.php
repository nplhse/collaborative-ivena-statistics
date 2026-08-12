<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\Contract\ProjectionEarliestDateProviderInterface;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use App\Statistics\Application\SummarizedReport\ReportBuildResult;
use App\Statistics\Application\SummarizedReport\ReportTypeInterface;
use App\Statistics\UI\Http\Controller\SummarizedReportsPagePresenter;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class SummarizedReportsPagePresenterTest extends TestCase
{
    public function testPresentBuildsMonthOnlyPeriodMenusForMonthlyReport(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params = []): string => sprintf(
                '%s?%s',
                $routeName,
                http_build_query($params),
            ),
        );

        $earliestDateProvider = $this->createStub(ProjectionEarliestDateProviderInterface::class);
        $earliestDateProvider->method('getEarliestCreatedAt')->willReturn(
            new \DateTimeImmutable('2023-01-01 00:00:00', new \DateTimeZone('Europe/Berlin')),
        );

        $presenter = new SummarizedReportsPagePresenter(
            new StatisticsNavigationUrlBuilder($router),
            $earliestDateProvider,
        );

        $reportType = $this->createStub(ReportTypeInterface::class);
        $reportType->method('key')->willReturn('monthly');
        $reportType->method('labelTranslationKey')->willReturn('stats.reports.types.monthly.label');
        $reportType->method('descriptionTranslationKey')->willReturn('stats.reports.types.monthly.description');

        $request = new Request(
            query: ['scope' => 'public', 'year' => '2024', 'month' => '3'],
            attributes: ['_route' => 'app_stats_reports_show', 'type' => 'monthly'],
        );
        $request->setLocale('en');

        $model = $presenter->present(
            $request,
            $reportType,
            new ReportBuildResult('@Statistics/reports/types/monthly.html.twig', $this->monthlyReportView(2024, 3)),
        );

        self::assertNotNull($model->periodNavigation);
        self::assertTrue($model->periodNavigation->showSecondaryPicker);
        self::assertSame('2024', $model->periodNavigation->primaryDropdownLabel);
        self::assertNotEmpty($model->periodNavigation->primaryMenu);
        self::assertNotEmpty($model->periodNavigation->secondaryMenu);
        self::assertStringContainsString('year=2024', (string) $model->periodNavigation->previousUrl);
        self::assertStringContainsString('month=2', (string) $model->periodNavigation->previousUrl);
        self::assertStringContainsString('app_stats_reports_show', (string) $model->periodNavigation->previousUrl);
        foreach ($model->periodNavigation->primaryMenu as $item) {
            self::assertArrayHasKey('url', $item);
            self::assertStringNotContainsString('period=all', (string) $item['url']);
            self::assertStringNotContainsString('period=year', (string) $item['url']);
        }
    }

    private function monthlyReportView(int $year, int $month): MonthlyReportView
    {
        return new MonthlyReportView(
            periodLabel: 'March 2024',
            year: $year,
            month: $month,
            hasData: true,
            allocationCount: 10,
            allocationMomPercent: 5.0,
            allocationYoyPercent: null,
            withPhysicianPercent: 12.0,
            withPhysicianMomPercent: 1.0,
            medianTransportMinutes: 20.0,
            medianTransportMomMinutes: -1.5,
            resusPercent: 3.0,
            resusMomPercent: 0.5,
            urgencySegments: [],
            genderSegments: [],
            topDiagnoses: [],
            topOccasions: [],
            topDepartments: [],
            insights: [],
            dailyChart: [
                'chartType' => 'bar',
                'labels' => [],
                'counts' => [],
            ],
            importCreateUrl: '/import/new',
            dashboardUrl: '/statistics',
            benchmarkingUrl: '/statistics/benchmarking',
            previousMonthLabel: 'February 2024',
            previousYear: 2024,
            previousMonth: 2,
            nextMonthLabel: 'April 2024',
            nextYear: 2024,
            nextMonth: 4,
            nextEnabled: true,
        );
    }
}
