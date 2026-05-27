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
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReportsControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;

    use Factories;
    use ResetDatabase;

    public function testReportsPageIsDisplayedWithTable(): void
    {
        $client = $this->createClientAsRoleUser();

        UserFactory::createOne(['username' => 'stats-report-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        $import = ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Seeded Report Diagnosis Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Seeded Report Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'import' => $import,
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);
        $this->rebuildProjection([(int) $import->getId()]);

        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&report=top_diagnoses',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-explorer-sidebar"]');
        $this->assertSelectorExists('[data-testid="stats-reports-widget"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Rank');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Diagnosis');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Count');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Share');
    }

    /**
     * @param list<int> $importIds
     */
    private function rebuildProjection(array $importIds): void
    {
        $container = static::getContainer();
        $container->get(Connection::class)->executeStatement('TRUNCATE TABLE allocation_stats_projection');
        $rebuilder = $container->get(AllocationStatsProjectionRebuildInterface::class);
        foreach ($importIds as $importId) {
            $rebuilder->rebuildForImport($importId);
        }
    }

    public function testLimitParameterTenIsAccepted(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&limit=10',
        );

        $this->assertResponseIsSuccessful();
        $link = $crawler->filter('[data-testid="stats-reports-limit-10"]')->link();
        $this->assertStringContainsString('limit=10', $link->getUri());
    }

    public function testInvalidLimitFallsBackToTwentyFive(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&limit=invalid',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-limit-25"].active');
    }

    public function testReportsPageAcceptsScopeAndPeriodParameters(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all_time',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-heading-title"]');
        $this->assertSelectorExists('[data-testid="stats-heading-subtitle"]');
    }
}
