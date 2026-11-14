<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Twig;

use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportClosedDepartmentView;
use App\Statistics\Application\SummarizedReport\Monthly\Dto\MonthlyReportView;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * The trend icon colour follows what a change means for each figure, not its sign (#1230).
 */
final class MonthlyReportKpiTilesTemplateTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        if (self::$booted) {
            self::getContainer()->get(LocaleSwitcher::class)->reset();
        }

        parent::tearDown();
    }

    public function testIncreasesAreAssessedPerFigure(): void
    {
        $crawler = $this->render(physicianDelta: 2.5, transportDelta: 3.0, resusDelta: 0.5, closedDelta: 50.0, locale: 'en');

        $this->assertTile($crawler, 'stats-monthly-report-with-physician', '25 %', 'up', '+2.5 pp', 'text-green');
        $this->assertTile($crawler, 'stats-monthly-report-mean-transport', '18.4 min', 'up', '+3 min', 'text-red');
        $this->assertTile($crawler, 'stats-monthly-report-resus', '4 %', 'up', '+0.5 pp', 'text-green');
        $this->assertTile($crawler, 'stats-monthly-report-closed-department-count', '1,234', 'up', '+50 %', 'text-red');
    }

    public function testDecreasesAreAssessedPerFigure(): void
    {
        $crawler = $this->render(physicianDelta: -2.5, transportDelta: -3.0, resusDelta: -0.5, closedDelta: -50.0, locale: 'en');

        $this->assertTile($crawler, 'stats-monthly-report-with-physician', '25 %', 'down', '-2.5 pp', 'text-red');
        $this->assertTile($crawler, 'stats-monthly-report-mean-transport', '18.4 min', 'down', '-3 min', 'text-green');
        $this->assertTile($crawler, 'stats-monthly-report-resus', '4 %', 'down', '-0.5 pp', 'text-red');
        $this->assertTile($crawler, 'stats-monthly-report-closed-department-count', '1,234', 'down', '-50 %', 'text-green');
    }

    public function testUnchangedFiguresStayGrey(): void
    {
        $crawler = $this->render(physicianDelta: 0.0, transportDelta: 0.0, resusDelta: 0.0, closedDelta: 0.0, locale: 'en');

        foreach ([
            'stats-monthly-report-with-physician',
            'stats-monthly-report-mean-transport',
            'stats-monthly-report-resus',
            'stats-monthly-report-closed-department-count',
        ] as $testId) {
            $trend = $crawler->filter(\sprintf('[data-testid="%s"] [data-kpi-trend="flat"]', $testId));
            self::assertCount(1, $trend, $testId);
            self::assertStringContainsString('text-secondary', (string) $trend->filter('svg')->attr('class'), $testId);
        }
    }

    public function testFiguresWithoutAComparisonShowNoTrend(): void
    {
        $crawler = $this->render(physicianDelta: null, transportDelta: null, resusDelta: null, closedDelta: null, locale: 'en');

        self::assertSame('1,234', $this->text($crawler, '[data-testid="stats-monthly-report-kpis"] .h1'));
        self::assertSame('3', $this->text($crawler, '[data-testid="stats-monthly-report-closed-department-departments"] .h1'));
        self::assertCount(0, $crawler->filter('[data-kpi-trend]'));
    }

    public function testGermanFormatting(): void
    {
        $crawler = $this->render(physicianDelta: 2.5, transportDelta: -3.0, resusDelta: 0.5, closedDelta: 12.5, locale: 'de');

        $this->assertTile($crawler, 'stats-monthly-report-with-physician', '25 %', 'up', '+2,5 %-Punkte', 'text-green');
        $this->assertTile($crawler, 'stats-monthly-report-mean-transport', '18,4 Min.', 'down', '-3 Min.', 'text-green');
        $this->assertTile($crawler, 'stats-monthly-report-closed-department-count', '1.234', 'up', '+12,5 %', 'text-red');
        self::assertSame(
            '30% aller Zuweisungen',
            $this->text($crawler, '[data-testid="stats-monthly-report-closed-department-count"] .h1 + .small'),
        );
    }

    private function assertTile(Crawler $crawler, string $testId, string $value, string $direction, string $delta, string $iconClass): void
    {
        $tile = $crawler->filter(\sprintf('[data-testid="%s"]', $testId));
        self::assertCount(1, $tile, $testId);
        self::assertSame($value, $this->text($tile, '.h1'), $testId);

        $trend = $tile->filter(\sprintf('[data-kpi-trend="%s"]', $direction));
        self::assertCount(1, $trend, $testId);
        self::assertSame($delta, $this->text($trend, '.lh-1'), $testId);
        self::assertStringContainsString($iconClass, (string) $trend->filter('svg')->attr('class'), $testId);
        // The delta text never carries the assessment colour.
        self::assertStringContainsString('text-secondary', (string) $trend->filter('.lh-1')->attr('class'), $testId);
    }

    private function render(?float $physicianDelta, ?float $transportDelta, ?float $resusDelta, ?float $closedDelta, string $locale): Crawler
    {
        self::bootKernel();
        self::getContainer()->get(LocaleSwitcher::class)->setLocale($locale);

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');
        $html = $twig->render('@Statistics/reports/types/monthly.html.twig', [
            'report' => $this->view($physicianDelta, $transportDelta, $resusDelta, $closedDelta),
        ]);

        return new Crawler('<!DOCTYPE html><html><body>'.$html.'</body></html>');
    }

    private function text(Crawler $crawler, string $selector): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $crawler->filter($selector)->text()));
    }

    private function view(?float $physicianDelta, ?float $transportDelta, ?float $resusDelta, ?float $closedDelta): MonthlyReportView
    {
        return new MonthlyReportView(
            periodLabel: 'March 2024',
            year: 2024,
            month: 3,
            hasData: true,
            allocationCount: 1234,
            withPhysicianPercent: 25.0,
            withPhysicianMomPercent: $physicianDelta,
            meanTransportMinutes: 18.4,
            meanTransportMomMinutes: $transportDelta,
            resusPercent: 4.0,
            resusMomPercent: $resusDelta,
            urgencySegments: [],
            genderSegments: [],
            topDiagnoses: [],
            topOccasions: [],
            topDepartments: [],
            closedDepartment: new MonthlyReportClosedDepartmentView(
                1234,
                30.0,
                $closedDelta,
                3,
                12,
                [],
                '/statistics/closed-department-assignments',
            ),
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
