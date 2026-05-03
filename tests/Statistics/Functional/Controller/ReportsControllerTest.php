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
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReportsControllerTest extends WebTestCase
{
    use Factories;
    use ResetDatabase;

    public function testReportsPageIsDisplayedWithTable(): void
    {
        $client = static::createClient();

        UserFactory::createOne(['username' => 'stats-report-test']);
        StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Dispatch Area']);
        HospitalFactory::createOne(['name' => 'Test Hospital']);
        ImportFactory::createOne(['name' => 'Test Import']);
        SpecialityFactory::createOne(['name' => 'Innere Medizin']);
        DepartmentFactory::createOne(['name' => 'Kardiologie']);
        AssignmentFactory::createOne(['name' => 'Test Assignment']);
        OccasionFactory::createOne(['name' => 'Test Occasion']);
        InfectionFactory::createOne(['name' => 'Test Infection']);
        $raw = IndicationRawFactory::createOne(['name' => 'Seeded Report Diagnosis Raw']);
        $normalized = IndicationNormalizedFactory::createOne(['name' => 'Seeded Report Diagnosis']);
        AllocationFactory::createOne([
            'createdAt' => new \DateTimeImmutable('today'),
            'indicationRaw' => $raw,
            'indicationNormalized' => $normalized,
        ]);

        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&report=top_diagnoses',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-filter-bar"]');
        $this->assertSelectorExists('[data-testid="stats-reports-widget"]');
        $this->assertSelectorExists('[data-testid="stats-reports-description"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Rank');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Diagnosis');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Count');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-table-card"]', 'Share');
    }

    public function testLimitParameterTenIsAccepted(): void
    {
        $client = static::createClient();
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
        $client = static::createClient();
        $client->request(
            Request::METHOD_GET,
            '/statistics/reports?scope=public&period=all&limit=invalid',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-reports-limit-25"].active');
    }
}
