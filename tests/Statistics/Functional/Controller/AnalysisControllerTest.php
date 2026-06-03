<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\Infrastructure\MaterializedView\MaterializedViewRefresher;
use App\Statistics\Infrastructure\MaterializedView\StatisticsMaterializedViewGroups;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AnalysisControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testAnalysisPageIsDisplayedWithChart(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-explorer-sidebar"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-widget"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-style"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-chart-summary"]', 'Mean');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-chart-summary"]', 'Std. deviation');
    }

    public function testAnalysisPageTableView(): void
    {
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $this->assertSelectorNotExists('[data-testid="stats-analysis-chart-measure"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-style"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-resources"].active');
    }

    public function testAnalysisFeaturesDimensionChartGroupedBars(): void
    {
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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

    public function testAnalysisAliasAllocationsOverTimeResolvesToAllocationsByMonth(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=allocations_over_time&view=chart&dimension=features',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-dimension-features"].active');
    }

    public function testAnalysisResourcesChartShareMeasureUsesPercentScale(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&view=chart&dimension=resources&chart_measure=share',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-chart-measure"]');
        $specRaw = $crawler->filter('[data-controller="analysis-chart"]')->first()->attr('data-analysis-chart-spec-value');
        self::assertNotNull($specRaw);
        $this->assertStringContainsString('"series"', $specRaw);
        $this->assertStringContainsString('"percentScale"', $specRaw);
        $this->assertStringNotContainsString('"barGrouped"', $specRaw);
    }

    public function testAnalysisAllTimeTotalChartUsesTwelveCalendarMonthBuckets(): void
    {
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
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
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=pivot&rows=department&cols=urgency',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'Department');
    }

    public function testAnalysisDropdownContainsAllocationAndHospitalPivotEntries(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-explorer-sidebar"]', 'Allocations over time');
        $this->assertSelectorTextContains('[data-testid="stats-explorer-sidebar"]', 'Allocations Pivot');
        $this->assertSelectorTextContains('[data-testid="stats-explorer-sidebar"]', 'Hospitals Pivot');
    }

    public function testAllocationPivotShowsMeasureSelectorAndSupportsRowPercent(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=allocation_pivot&rows=urgency&cols=gender&measure=row_percent',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-measure"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', '%');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"] tfoot', '100.0%');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-dimension-selector"]');
    }

    public function testHospitalPivotSupportsConfiguredDimensionsAndMeasures(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=hospital_pivot&rows=state&cols=tier&measure=hospital_count',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-table-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-rows"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-cols"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-measure"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'State');
    }

    public function testHospitalPivotSupportsMinMaxMeasuresAndRowPercent(): void
    {
        $client = $this->createClientAsRoleUser();

        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=hospital_pivot&rows=state&cols=tier&measure=min_beds',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-table-card"]');

        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=hospital_pivot&rows=state&cols=tier&measure=max_allocations',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-table-card"]');

        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=hospital_pivot&rows=state&cols=tier&measure=row_percent',
        );
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', '%');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"] tfoot', '100.0%');
    }

    public function testComparisonAnalysisIsReachableAndShowsComparisonColumns(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=allocations_comparison_over_time&view=table&comparison_scope=hospital_cohort:urban_basic&comparison_period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Public (Last 12 months)');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Delta');
    }

    public function testComparisonAnalysisDefaultsComparisonScopeWhenMissing(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedEligibleUrbanBasicCohort($client);
        $client->followRedirects(true);
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=allocations_comparison_over_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-comparison-scope-menu"]', 'Urban Location Basic Tier');
    }

    public function testComparisonAnalysisSupportsDifferentComparisonPeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=year&year=2025&analysis=allocations_comparison_over_time&comparison_scope=hospital_cohort:urban_basic&comparison_period=year&comparison_year=2024&view=table',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Public (2025)');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Public (2024)');
    }

    public function testComparisonAnalysisDefaultsComparisonPeriodToPrimaryPeriodWhenMissing(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=year&year=2025&analysis=allocations_comparison_over_time&comparison_scope=hospital_cohort:urban_basic',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('comparison_period=year', $location);
        $this->assertStringContainsString('comparison_year=2025', $location);
    }

    public function testComparisonAnalysisSupportsPublicComparisonScope(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(true);
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=allocations_comparison_over_time&comparison_scope=public&view=table',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-comparison-scope-menu"]');
    }

    public function testAnalysisShowsPeriodNavigationWithYearPeriod(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=year&year=2021&view=chart',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
        $this->assertSelectorTextContains('[data-testid="stats-period-secondary"]', '2021');
        $previousHref = $crawler->filter('[data-testid="stats-period-nav-previous"] a.page-link[href]')->attr('href');
        $this->assertStringContainsString('/statistics/analysis', $previousHref);
        $this->assertStringContainsString('view=chart', $previousHref);
        $this->assertStringContainsString('year=2020', $previousHref);
    }

    public function testAnalysisAllTimeHidesPeriodNavigation(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all_time&view=chart',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-period-navigation"]');
        $this->assertSelectorExists('[data-testid="stats-period-primary"]');
    }

    private function seedEligibleUrbanBasicCohort(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $user = UserFactory::createOne(['username' => 'analysis-cohort-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'Analysis Cohort State']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Analysis Cohort Dispatch']);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'Analysis Cohort Hospital A',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'Analysis Cohort Hospital B',
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::URBAN,
        ]);
        SpecialityFactory::createOne(['name' => 'Analysis Cohort Spec']);
        DepartmentFactory::createOne(['name' => 'Analysis Cohort Dept']);
        AssignmentFactory::createOne(['name' => 'Analysis Cohort Assign']);
        OccasionFactory::createOne(['name' => 'Analysis Cohort Occ']);
        InfectionFactory::createOne(['name' => 'Analysis Cohort Inf']);
        $raw = IndicationRawFactory::createOne(['name' => 'Analysis Cohort Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Analysis Cohort Norm']);
        $importA = ImportFactory::createOne(['name' => 'Analysis Cohort Import A', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'Analysis Cohort Import B', 'hospital' => $hospitalB, 'createdBy' => $user]);

        foreach ([[$importA, $hospitalA], [$importB, $hospitalB]] as [$import, $hospital]) {
            AllocationFactory::createOne([
                'createdAt' => new \DateTimeImmutable('today'),
                'import' => $import,
                'hospital' => $hospital,
                'state' => $state,
                'dispatchArea' => $dispatchArea,
                'indicationRaw' => $raw,
                'indicationNormalized' => $normalized,
            ]);
        }

        $container = $client->getContainer();
        $rebuilder = $container->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        $container->get(MaterializedViewRefresher::class)->refresh(
            [StatisticsMaterializedViewGroups::OVERVIEW],
            concurrently: false,
        );
    }
}
