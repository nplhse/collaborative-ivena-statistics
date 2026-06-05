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
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class GenericAnalysisControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testAllocationsByMonthRedirectsToAnalyticsView(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/allocations_by_month?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-title"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-table"]');

        $specsRaw = $crawler->filter('[data-controller="generic-analysis-chart"]')->attr('data-generic-analysis-chart-specs-value');
        self::assertNotNull($specsRaw);
        $this->assertStringContainsString('"bar"', $specsRaw);
    }

    public function testUrgencyByMonthRedirectsToAnalyticsViewWithChartTypeSelector(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);
        $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/urgency_by_month?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-type"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-type-stacked_bar"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-type-line"]');
    }

    public function testUnknownPresetReturnsNotFound(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/unknown_preset?scope=public&period=all',
        );

        $this->assertResponseStatusCodeSame(404);
    }

    public function testMetricOverridesShowMultipleTableColumns(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/allocations_by_month?scope=public&period=all&'
            .'ga_metrics[]=count&ga_metrics[]=percent_of_total&ga_visual_metric=count',
        );

        $this->assertResponseIsSuccessful();
        $headers = $crawler->filter('[data-testid="stats-generic-analysis-table-stacked"] thead th')
            ->each(static fn ($node): string => trim((string) $node->text()));
        self::assertGreaterThanOrEqual(2, \count($headers));
    }

    public function testAgeGroupDistributionShowsRowLimitControl(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithAllocation();
        $client->followRedirects(true);
        $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/age_group_distribution?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-row-limit"]');
        $this->assertSelectorNotExists('[data-testid="stats-generic-analysis-table-row-limit"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-row-limit-5"]');
    }

    public function testCustomPresetRedirectsToBuilder(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/custom?scope=public&period=all&'
            .GenericAnalysisQueryKeys::PRIMARY.'=month&'
            .GenericAnalysisQueryKeys::SERIES.'=urgency',
        );

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/statistics/analytics/builder', $location);
        $this->assertStringContainsString('scope=public', $location);
    }

    public function testAllocationsByHospitalCohortDoesNotExposeRawTranslationKeys(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedProjectionWithTwoUrbanBasicHospitals();
        $client->followRedirects(true);
        $client->request(
            Request::METHOD_GET,
            '/statistics/generic-analysis/allocations_by_hospital_cohort?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('stats.filter.cohort.', $content);
        self::assertStringContainsString('Urban Location Basic Tier', $content);
    }

    private function seedProjectionWithTwoUrbanBasicHospitals(): void
    {
        $user = UserFactory::createOne(['username' => 'generic-analysis-cohort-test']);
        $state = StateFactory::createOne(['name' => 'GA Cohort State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'GA Cohort Dispatch']);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'GA Cohort Hospital A',
            'tier' => \App\Allocation\Domain\Enum\HospitalTier::BASIC,
            'location' => \App\Allocation\Domain\Enum\HospitalLocation::URBAN,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'GA Cohort Hospital B',
            'tier' => \App\Allocation\Domain\Enum\HospitalTier::BASIC,
            'location' => \App\Allocation\Domain\Enum\HospitalLocation::URBAN,
        ]);
        $importA = ImportFactory::createOne(['name' => 'GA Cohort Import A', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'GA Cohort Import B', 'hospital' => $hospitalB, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'GA Cohort Speciality']);
        DepartmentFactory::createOne(['name' => 'GA Cohort Department']);
        AssignmentFactory::createOne(['name' => 'GA Cohort Assignment']);
        OccasionFactory::createOne(['name' => 'GA Cohort Occasion']);
        InfectionFactory::createOne(['name' => 'GA Cohort Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'GA Cohort Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'GA Cohort Normalized']);

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

        $this->rebuildProjection([(int) $importA->getId(), (int) $importB->getId()]);
    }

    private function seedProjectionWithAllocation(): void
    {
        $user = UserFactory::createOne(['username' => 'generic-analysis-test']);
        $state = StateFactory::createOne(['name' => 'GA State', 'createdBy' => $user]);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'GA Dispatch']);
        $hospital = HospitalFactory::createOne(['name' => 'GA Hospital']);
        $import = ImportFactory::createOne(['name' => 'GA Import', 'hospital' => $hospital, 'createdBy' => $user]);
        SpecialityFactory::createOne(['name' => 'GA Speciality']);
        DepartmentFactory::createOne(['name' => 'GA Department']);
        AssignmentFactory::createOne(['name' => 'GA Assignment']);
        OccasionFactory::createOne(['name' => 'GA Occasion']);
        InfectionFactory::createOne(['name' => 'GA Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'GA Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'GA Normalized']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $this->rebuildProjection([(int) $import->getId()]);
    }

    /**
     * @param list<int> $importIds
     */
    private function rebuildProjection(array $importIds): void
    {
        $container = self::getContainer();
        $container->get(Connection::class)->executeStatement('TRUNCATE TABLE allocation_stats_projection');
        $rebuilder = $container->get(AllocationStatsProjectionRebuildInterface::class);
        foreach ($importIds as $importId) {
            $rebuilder->rebuildForImport($importId);
        }
    }
}
