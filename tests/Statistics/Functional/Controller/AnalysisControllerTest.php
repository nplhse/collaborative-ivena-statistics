<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AnalysisControllerTest extends WebTestCase
{
    public function testAnalysisPageIsDisplayedWithChart(): void
    {
        $client = static::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-widget"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-style"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-chart-summary"]', 'Mean');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-chart-summary"]', 'Std. deviation');
    }

    public function testAnalysisPageTableView(): void
    {
        $client = static::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=table',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Month');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Count');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Share');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Total');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-summary"]', 'Mean');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-summary"]', 'Std. deviation');
    }

    public function testAnalysisGenderDimensionChartEmbedsSeriesPayload(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=gender',
        );

        $this->assertResponseIsSuccessful();
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        $this->assertStringContainsString('"series"', $specRaw);
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-gender"].active');
    }

    public function testAnalysisUrgencyDimensionTableShowsShortUrgencyHeaders(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=table&dimension=urgency',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'U1');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'U2');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'U3');
        $this->assertSelectorTextNotContains('[data-testid="stats-analysis-table-card"] thead', 'Share');

        $firstRowText = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr')->eq(0)->text();
        $this->assertMatchesRegularExpression('/\(\s*(?:—|\d+\.\d+%)\s*\)/', $firstRowText);

        $tfootText = $crawler->filter('[data-testid="stats-analysis-table-card"] tfoot tr')->text();
        $this->assertMatchesRegularExpression('/\(\s*(?:—|\d+\.\d+%)\s*\)/', $tfootText);
    }

    public function testAnalysisResourcesDimensionTableShowsResourceColumns(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=table&dimension=resources',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Month');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Allocations (month total)');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Cath lab required');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Resuscitation room required');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-summary"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-resources"].active');

        $firstRowText = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr')->eq(0)->text();
        $this->assertMatchesRegularExpression('/\(\s*(?:—|\d+\.\d+%)\s*\)/', $firstRowText);
    }

    public function testAnalysisFeaturesDimensionTableShowsClinicalColumns(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=table&dimension=features',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Month');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Allocations (month total)');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'With physician');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-summary"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-features"].active');

        $firstRowText = $crawler->filter('[data-testid="stats-analysis-table-card"] tbody tr')->eq(0)->text();
        $this->assertMatchesRegularExpression('/\(\s*(?:—|\d+\.\d+%)\s*\)/', $firstRowText);
    }

    public function testAnalysisResourcesDimensionChartGroupedBarsAndBarLayoutToggle(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=resources',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-card"]');
        $this->assertCount(1, $crawler->filter('[data-controller="analysis-chart"]'));
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        $this->assertStringContainsString('"series"', $specRaw);
        $this->assertStringContainsString('"barGrouped"', $specRaw);
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-measure-absolute"].active');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-style"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-resources"].active');
    }

    public function testAnalysisFeaturesDimensionChartGroupedBars(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=features',
        );

        $this->assertResponseIsSuccessful();
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        $this->assertStringContainsString('"series"', $specRaw);
        $this->assertStringContainsString('"barGrouped"', $specRaw);
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-features"].active');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-chart-measure"]');
    }

    public function testAnalysisFeaturesChartIgnoresChartMeasureShareQuery(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=features&chart=bar&chart_measure=share',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-chart-measure-share"]');
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        $this->assertStringNotContainsString('"percentScale"', $specRaw);
        $this->assertStringContainsString('"barGrouped"', $specRaw);
    }

    public function testAnalysisResourcesChartShareMeasureUsesPercentScale(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=resources&chart_measure=share',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-measure-share"].active');
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        $this->assertStringContainsString('"series"', $specRaw);
        $this->assertStringContainsString('"percentScale"', $specRaw);
        $this->assertStringNotContainsString('"barGrouped"', $specRaw);
    }

    public function testAnalysisAllTimeTotalChartUsesTwelveCalendarMonthBuckets(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all_time&view=chart&dimension=total',
        );

        $this->assertResponseIsSuccessful();
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        /** @var array{labels: list<string>, counts: list<int>} $spec */
        $spec = json_decode(html_entity_decode($specRaw, \ENT_QUOTES | \ENT_HTML5), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('labels', $spec);
        self::assertArrayHasKey('counts', $spec);
        $this->assertCount(12, $spec['labels']);
        $this->assertCount(12, $spec['counts']);
    }

    public function testAnalysisMonthPeriodTotalChartUsesDailyBuckets(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=month&year=2025&month=6&view=chart&dimension=total',
        );

        $this->assertResponseIsSuccessful();
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        /** @var array{labels: list<string>, counts: list<int>} $spec */
        $spec = json_decode(html_entity_decode($specRaw, \ENT_QUOTES | \ENT_HTML5), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertCount(30, $spec['labels']);
        $this->assertCount(30, $spec['counts']);
    }

    public function testAnalysisResourcesShareChartUsesPercentScaleAndOptionalVirtualRemainder(): void
    {
        $client = static::createClient();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=resources&chart_measure=share',
        );

        $this->assertResponseIsSuccessful();
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        /** @var array{series: list<array{name: string}>} $spec */
        $spec = json_decode(html_entity_decode($specRaw, \ENT_QUOTES | \ENT_HTML5), true, 512, \JSON_THROW_ON_ERROR);
        $names = array_column($spec['series'], 'name');
        $this->assertGreaterThanOrEqual(2, \count($names));
        $this->assertLessThanOrEqual(3, \count($names));
        if (3 === \count($names)) {
            $this->assertStringContainsString('Virtual remainder', $names[0]);
        }
    }

    public function testPivotAnalysisShowsPivotTable(): void
    {
        $client = static::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=pivot&view=table',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-table-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-rows"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-cols"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-dimension-selector"]');
    }

    public function testPivotAnalysisInvalidRowsColsFallsBackToDefaultUrgencyByGender(): void
    {
        $client = static::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=pivot&rows=department&cols=gender',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'Urgency');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'Male');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'Female');
    }

    public function testPivotAnalysisDepartmentByUrgencyShowsDepartmentHeader(): void
    {
        $client = static::createClient();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=pivot&rows=department&cols=urgency',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'Department');
    }
}
