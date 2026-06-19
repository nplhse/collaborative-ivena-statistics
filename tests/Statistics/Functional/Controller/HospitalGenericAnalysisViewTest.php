<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalGenericAnalysisViewTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testHospitalsByTierViewRendersChartAndPopulationControl(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/hospitals_by_tier?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analytics-view-title"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-select"]');
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
    }

    public function testHospitalTierByLocationPivotRendersTable(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/hospital_tier_by_location?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-pivot-table-card"]');
    }

    public function testCompareModeRendersGroupedSeries(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analytics/view/hospitals_by_tier_compare?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-generic-analysis-chart-card"]');
        $selectedPopulation = $client->getCrawler()->filter('[data-testid="stats-hospital-population-select"] option[selected]');
        self::assertGreaterThan(0, $selectedPopulation->count());
        self::assertStringContainsString('compare', (string) $selectedPopulation->attr('value'));
    }
}
