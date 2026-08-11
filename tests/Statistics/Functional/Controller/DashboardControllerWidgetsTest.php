<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\HttpFoundation\Request;

final class DashboardControllerWidgetsTest extends DashboardControllerTestCase
{
    public function testStatisticsOverviewIsDisplayed(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorExists('[data-testid="stats-heading-subtitle"]');
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorTextContains('[data-testid="stats-heading-subtitle"]', 'Statistics');
        $this->assertSelectorTextContains('[data-testid="stats-heading-title"]', 'Overview');
        $this->assertSelectorExists('[data-testid="stats-executive-dashboard"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-summary"]');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Total allocations');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Gender distribution');
        $this->assertSelectorTextContains('[data-testid="stats-hospital-summary"]', 'Emergency');
        $this->assertSelectorExists('[data-testid="stats-overview-self-benchmark-frame"]');
        self::assertSame(
            '_top',
            $crawler->filter('[data-testid="stats-overview-self-benchmark-frame"]')->attr('target'),
        );
        self::assertStringContainsString(
            '/statistics/overview/self-benchmark',
            (string) $crawler->filter('[data-testid="stats-overview-self-benchmark-frame"]')->attr('src'),
        );
        $this->assertSelectorExists('[data-testid="stats-overview-self-benchmark-placeholder"]');
        $this->assertSelectorExists('[data-testid="stats-overview-self-benchmark-placeholder"] .placeholder-glow');
        $this->assertSelectorNotExists('[data-testid="stats-executive-kpis"]');
        $this->assertSelectorExists('[data-testid="stats-executive-indications"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-reports-frame"]');
        self::assertSame(
            '_top',
            $crawler->filter('[data-testid="stats-overview-top-reports-frame"]')->attr('target'),
        );
        $this->assertSelectorExists('[data-testid="stats-overview-top-reports-placeholder"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-reports-placeholder"] .placeholder-glow');
        $this->assertSelectorNotExists('[data-testid="stats-overview-top-specialities"]');
        $this->assertSelectorExists('[data-testid="stats-overview-time-series"]');
        $this->assertSelectorExists('[data-testid="stats-overview-heatmap"]');
        $this->assertSelectorExists('[data-testid="stats-overview-age-groups"]');
        $this->assertSelectorExists('[data-testid="stats-overview-transport"]');
        $this->assertSelectorExists('[data-testid="stats-overview-transport-time"]');
        $this->assertSelectorExists('[data-testid="stats-overview-features"]');
        $this->assertSelectorExists('[data-testid="stats-overview-resources"]');
        $this->assertSelectorExists('[data-testid="stats-charts"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-indicator"]');
        $this->assertSelectorExists('[data-testid="stats-data-quality-drawer"]');
        $this->assertSelectorNotExists('[data-testid="stats-data-quality-dimensions-table"]');
    }

    public function testOverviewRendersHospitalInsightsWhenBenchmarkDataIsSufficient(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedOverviewHospitalInsightsScenario();

        $client->request(
            Request::METHOD_GET,
            '/statistics/overview/self-benchmark?scope=public&period=month&year=2026&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-overview-hospital-insights"]');
        $this->assertGreaterThanOrEqual(
            1,
            $client->getCrawler()->filter('[data-testid^="stats-overview-hospital-insight-"]')->count(),
        );
    }

    public function testOverviewDataQualityIndicatorUsesProgressivePrefetch(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all_time');

        $this->assertResponseIsSuccessful();

        $wrapper = $crawler->filter('[data-controller="data-quality-indicator"]');
        self::assertGreaterThan(0, $wrapper->count());
        self::assertStringContainsString(
            '/statistics/data-quality/drawer',
            (string) $wrapper->attr('data-data-quality-indicator-url-value'),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-data-quality-indicator-target="badge"]')->count(),
        );
    }

    public function testOverviewRendersPortalNavigationLinks(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all_time');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-cross-nav-overview-benchmarking"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-time-series"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-heatmap-hour"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-heatmap-weekday"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-age-groups"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-gender"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-urgency"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-resources"]');
        $this->assertSelectorExists('[data-testid="stats-cross-nav-overview-indicators"]');
        $this->assertSelectorTextContains('[data-testid="stats-cross-nav-overview-time-series"]', 'Cases over time');
        $this->assertSelectorTextContains('[data-testid="stats-cross-nav-overview-age-groups"]', 'Age groups');
        $this->assertSelectorTextContains('[data-testid="stats-cross-nav-overview-resources"]', 'Resources');
        $this->assertSelectorTextContains('[data-testid="stats-cross-nav-overview-indicators"]', 'Clinical features');
    }

    public function testOverviewResourcesAndClinicalFeaturesLinkToComparisonExplorerViews(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all_time');

        $this->assertResponseIsSuccessful();

        $resourcesHref = (string) $crawler->filter('[data-testid="stats-cross-nav-overview-resources"]')->attr('href');
        self::assertStringContainsString('/statistics/analysis/explorer/overview-clinical-resources', $resourcesHref);
        self::assertStringContainsString('period=all', $resourcesHref);

        $clinicalHref = (string) $crawler->filter('[data-testid="stats-cross-nav-overview-indicators"]')->attr('href');
        self::assertStringContainsString('/statistics/analysis/explorer/overview-clinical-features', $clinicalHref);
        self::assertStringContainsString('period=all', $clinicalHref);
    }

    public function testOverviewTopReportsLazyEndpointRendersAllCards(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/overview/top-reports?scope=public&period=month&year=2025&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-overview-top-reports');
        $this->assertSelectorExists('[data-testid="stats-overview-top-specialities"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-departments"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-assignments"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-occasions"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-infections"]');
        $this->assertSelectorExists('[data-testid="stats-overview-top-secondary-indications"]');
    }

    public function testOverviewSelfBenchmarkLazyEndpointRendersKpis(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/overview/self-benchmark?scope=public&period=month&year=2025&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-overview-self-benchmark');
        $this->assertSelectorExists('[data-testid="stats-executive-kpis"]');
        $this->assertCount(6, $crawler->filter('[data-testid="stats-executive-kpis"] .card'));
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-cases_per_day"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-median_age"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-age_80_plus"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-night_daytime"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-weekend"]');
        $this->assertSelectorExists('[data-testid="stats-executive-kpi-median_transport"]');
    }

    public function testDataQualityDrawerEndpointRendersDimensions(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/data-quality/drawer?scope=public&period=month&year=2025&month=6',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-data-quality-dimensions-table"]');
    }

    public function testOverviewHeatmapToggleMarkupAndPayload(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();

        $shiftButton = $crawler->filter('[data-testid="stats-overview-heatmap"] [data-indication-dashboard-charts-target="heatmapModeShift"]');
        $this->assertCount(1, $shiftButton);
        self::assertSame(
            'indication-dashboard-charts#setHeatmapMode',
            $shiftButton->attr('data-action'),
        );
        self::assertSame('shift', $shiftButton->attr('data-indication-dashboard-charts-mode-param'));

        $payloadRaw = (string) $crawler->filter('[data-testid="stats-charts"]')->attr('data-indication-dashboard-charts-payload-value');
        /** @var array<string, mixed> $payload */
        $payload = json_decode(html_entity_decode($payloadRaw, ENT_QUOTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('heatmapDayTime', $payload);
        self::assertArrayHasKey('heatmapShift', $payload);
        self::assertNotSame(
            $payload['heatmapDayTime']['columnLabels'] ?? [],
            $payload['heatmapShift']['columnLabels'] ?? [],
        );
    }

    public function testOverviewFeatureAndResourceCardsHaveRows(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=month&year=2025&month=6');

        $this->assertResponseIsSuccessful();
        $this->assertCount(7, $crawler->filter('[data-testid="stats-overview-features"] .progress'));
        $this->assertCount(2, $crawler->filter('[data-testid="stats-overview-resources"] .progress'));
    }

    private function seedOverviewHospitalInsightsScenario(): void
    {
        $user = UserFactory::createOne(['username' => 'overview-insights-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'OverviewInsightsState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'OverviewInsightsDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'OverviewInsightsHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'OverviewInsightsSpec']);
        DepartmentFactory::createOne(['name' => 'OverviewInsightsDept']);
        AssignmentFactory::createOne(['name' => 'OverviewInsightsAssign']);
        IndicationRawFactory::createOne(['name' => 'OverviewInsightsRaw', 'code' => 912_361]);
        $indicationNormalized = IndicationNormalizedFactory::createOne(['name' => 'OverviewInsightsNorm']);

        $import = ImportFactory::createOne([
            'name' => 'OverviewInsightsImport',
            'hospital' => $hospital,
            'createdBy' => $user,
        ]);

        $allocationDefaults = [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'indicationNormalized' => $indicationNormalized,
            'isWithPhysician' => true,
        ];

        AllocationFactory::createMany(340, $allocationDefaults + [
            'createdAt' => new \DateTimeImmutable('2026-02-15 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-02-15 09:20:00'),
        ]);
        AllocationFactory::createMany(50, $allocationDefaults + [
            'createdAt' => new \DateTimeImmutable('2026-05-10 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-05-10 10:20:00'),
        ]);
        AllocationFactory::createMany(110, $allocationDefaults + [
            'createdAt' => new \DateTimeImmutable('2026-06-10 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-06-10 11:20:00'),
        ]);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
        $this->refreshOverviewMaterializedViews();
    }
}
