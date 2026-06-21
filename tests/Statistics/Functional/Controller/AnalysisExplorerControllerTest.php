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
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AnalysisExplorerControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;

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

        $specsRaw = $chart->attr('data-generic-analysis-chart-specs-value');
        self::assertNotNull($specsRaw);
        $this->assertStringContainsString('"bar"', $specsRaw);
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-open"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-drawer"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-section-scope"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-edit-section-period"]');
        $this->assertSelectorNotExists('[data-testid="stats-scope-primary"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table-body"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-explorer-table-footer"]');
    }

    public function testExistingAnalyticsViewStillWorks(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);

        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/allocations_by_month?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-title"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
    }

    private function seedProjectionWithAllocation(): void
    {
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
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $this->rebuildProjectionForImports([(int) $import->getId()]);
    }
}
