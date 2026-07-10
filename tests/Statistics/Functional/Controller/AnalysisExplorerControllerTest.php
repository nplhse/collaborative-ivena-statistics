<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

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
use App\Tests\Statistics\Support\SeedsExplorerSystemViewsTrait;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;
    use SeedsExplorerSystemViewsTrait;

    public function testExplorerRendersHospitalsWithAvgBedsMetric(): void
    {
        $client = $this->createClientAsRoleUser();
        HospitalFactory::createOne(['name' => 'Beds Hospital', 'beds' => 120]);
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer?dataSource=hospitals&scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-additional-table-metrics-field"]');
    }

    public function testExplorerRendersHospitalsDataSource(): void
    {
        $client = $this->createClientAsRoleUser();
        HospitalFactory::createOne(['name' => 'Explorer Hospital Master']);
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer?dataSource=hospitals&scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-data-source-switcher"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table"]');
    }

    public function testHospitalSavedViewOpensWithoutDataSourceParam(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        HospitalFactory::createOne(['name' => 'Tier Hospital']);
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/hospitals-by-tier?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-config-warning"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-data-source-switcher"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-title"]', 'Hospitals by tier');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');
    }

    public function testHospitalBedsDistributionSavedViewRendersBoxPlotTable(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        HospitalFactory::createOne(['name' => 'Box Plot Hospital', 'beds' => 80]);
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/beds-distribution-by-tier?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-config-warning"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-title"]', 'Beds distribution by tier');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');
        $this->assertSelectorExists('[data-generic-analysis-chart-default-type-value="box_plot"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table"]');
    }

    public function testTransportTimeDistributionSavedViewRendersBoxPlotTable(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation(arrivalAt: new \DateTimeImmutable('2026-01-15 10:00:00'));
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/transport-time-distribution-by-urgency?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-config-warning"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-title"]',
            'Transport time distribution by urgency',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');
        $this->assertSelectorExists('[data-generic-analysis-chart-default-type-value="box_plot"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table"]');
    }

    public function testHospitalCompareSavedViewOpensWithoutFormCycleError(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        HospitalFactory::createOne(['name' => 'Compare Hospital', 'isParticipating' => true]);
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/hospitals-by-tier-compare?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-config-warning"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-title"]',
            'Hospitals by tier (participation compare)',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');
    }

    public function testInvalidDataSourceFallsBackToAllocations(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer?dataSource=unknown&scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-data-source-switcher"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-chart-title"]', 'Allocations over time');
    }

    public function testExplorerRendersAllocationsOverTimeChart(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-title"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-title"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-chart-title"]', 'Allocations over time');

        $chart = $crawler->filter('[data-controller="generic-analysis-chart"]');
        self::assertGreaterThan(0, $chart->count());
        self::assertSame('explorer', $chart->attr('data-generic-analysis-chart-chart-height-profile-value'));

        $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
        self::assertNotNull($specsRaw);
        $this->assertStringContainsString('"bar"', $specsRaw);
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table-scroll"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-actions"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-library-link"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-library-link"]', 'Open library');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-open"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-drawer"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-section-scope"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-section-period"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-section-analysis"]');
        $this->assertSelectorNotExists('[data-testid="stats-scope-primary"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table-body"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table-footer"]');
    }

    /**
     * @return \Generator<string, array{0: string, 1: string, 2: string}>
     */
    public static function demoViewProvider(): \Generator
    {
        yield 'allocations over time' => ['allocations-over-time', 'Allocations over time', '"line"'];
        yield 'allocations by year' => ['allocations-by-year', 'Allocations by year', '"line"'];
        yield 'gender distribution' => ['gender-distribution', 'Gender distribution', '"bar"'];
        yield 'gender over time' => ['gender-over-time', 'Gender over time', '"grouped_bar"'];
        yield 'urgency distribution' => ['urgency-distribution', 'Urgency distribution', '"bar"'];
        yield 'urgency over time' => ['urgency-over-time', 'Urgency over time', '"stacked_bar"'];
    }

    public function testSystemViewTitleIsLocalizedInGerman(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/locale/switch/de?_target_path=/statistics/analysis/explorer/allocations-over-time?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-title"]',
            'Allokationen im Zeitverlauf',
        );
    }

    public function testTransportTimeBucketDistributionViewOpensInExplorer(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation(new \DateTimeImmutable('today +30 minutes'));
        $client->followRedirects(true);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/transport-time-bucket-distribution?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-title"]',
            'Transport time bucket distribution',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');

        $chart = $crawler->filter('[data-controller="generic-analysis-chart"]');
        self::assertGreaterThan(0, $chart->count());
        $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
        self::assertNotNull($specsRaw);
        $this->assertStringContainsString('"bar"', $specsRaw);
    }

    public function testOverviewClinicalResourcesViewOpensInExplorer(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation(requiresResus: true);
        $client->followRedirects(true);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/overview-clinical-resources?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-title"]',
            'Clinical resources overview',
        );
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-section-analysis"]');

        $chart = $crawler->filter('[data-controller="generic-analysis-chart"]');
        self::assertGreaterThan(0, $chart->count());
        $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
        self::assertNotNull($specsRaw);
        $this->assertStringContainsString('"bar"', $specsRaw);
        $this->assertStringContainsString('"percentScale":true', $specsRaw);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('demoViewProvider')]
    public function testSavedViewOpensInExplorer(string $slug, string $title, string $chartType): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $crawler = $client->request(
            Request::METHOD_GET,
            sprintf('/statistics/analysis/explorer/%s?scope=public&period=all', $slug),
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-title"]', $title);
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-chart-card"]');

        $chart = $crawler->filter('[data-controller="generic-analysis-chart"]');
        self::assertGreaterThan(0, $chart->count());
        self::assertSame('explorer', $chart->attr('data-generic-analysis-chart-chart-height-profile-value'));
        $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
        self::assertNotNull($specsRaw);
        $this->assertStringContainsString($chartType, $specsRaw);
    }

    public function testUnknownSavedViewReturnsNotFound(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/unknown-view?scope=public&period=all',
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testInvalidSavedConfigShowsWarningAndDefaultAnalysis(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation();

        $repository = self::getContainer()->get(\App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository::class);
        $view = $repository->findBySlug('urgency-distribution');
        self::assertInstanceOf(\App\Statistics\Domain\Entity\SavedExplorerView::class, $view);
        $view->update(
            title: $view->getTitle(),
            category: $view->getCategory(),
            configJson: ['invalid' => true],
            description: $view->getDescription(),
        );
        $repository->save($view);

        $client->followRedirects(true);
        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/urgency-distribution?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-config-warning"]');
        $this->assertSelectorTextContains(
            '[data-testid="stats-analysis-explorer-config-warning"]',
            'invalid',
        );
        $this->assertSelectorTextContains('[data-testid="stats-analysis-explorer-chart-title"]', 'Allocations over time');
    }

    public function testSystemSavedViewShowsSaveAsAndFavoriteForParticipant(): void
    {
        $client = $this->createClientAsParticipant();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/allocations-over-time?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-favorite-toggle"]');
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-save"]');
    }

    public function testExistingAnalyticsViewStillWorks(): void
    {
        $client = $this->createClientAsRoleUser();

        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        $this->assertResponseRedirects(
            '/statistics/analysis/explorer/allocations-over-time?scope=public&period=all',
            301,
        );
    }

    public function testTemporalSavedViewDoesNotShowChartRowLimitControl(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedExplorerSystemViews();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis/explorer/gender-over-time?scope=public&period=all&chartTop=5',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-analysis-explorer-chart-row-limit"]');
    }

    private function seedProjectionWithAllocation(
        ?\DateTimeImmutable $arrivalAt = null,
        ?bool $requiresResus = null,
    ): void {
        $user = UserFactory::createOne(['username' => 'analysis-explorer-test']);
        $state = StateFactory::createOne(['name' => 'Explorer State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Explorer Dispatch']);
        $hospital = HospitalFactory::createOne(['name' => 'Explorer Hospital']);
        $import = ImportFactory::createOne(['name' => 'Explorer Import', 'hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'Explorer Speciality']);
        DepartmentFactory::createOne(['name' => 'Explorer Department']);
        AssignmentFactory::createOne(['name' => 'Explorer Assignment']);
        OccasionFactory::createOne(['name' => 'Explorer Occasion']);
        InfectionFactory::createOne(['name' => 'Explorer Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Explorer Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Explorer Normalized']);
        $allocationAttributes = [
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ];
        if ($arrivalAt instanceof \DateTimeImmutable) {
            $allocationAttributes['arrivalAt'] = $arrivalAt;
        }
        if (null !== $requiresResus) {
            $allocationAttributes['requiresResus'] = $requiresResus;
        }
        AllocationFactory::createOne($allocationAttributes);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }
}
