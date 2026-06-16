<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class PivotTablesControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testPivotPageDefaultsToAllocationPivot(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(true);
        $client->request(
            Request::METHOD_GET,
            '/statistics/pivot?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-table-card"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-rows"]');
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-cols"]');
    }

    public function testSidebarListsOnlyPivotTables(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(true);
        $client->request(
            Request::METHOD_GET,
            '/statistics/pivot?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $sidebar = $client->getCrawler()->filter('[data-testid="stats-explorer-sidebar"]')->text();
        $this->assertStringContainsString('Allocations Pivot', $sidebar);
        $this->assertStringContainsString('Hospitals Pivot', $sidebar);
        $this->assertStringNotContainsString('Allocations over time', $sidebar);
    }

    public function testAllocationPivotShowsMeasureSelectorAndSupportsRowPercent(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/pivot?scope=public&period=all&analysis=allocation_pivot&rows=urgency&cols=gender&measure=row_percent',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-measure"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', '%');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"] tfoot', '100.0%');
    }

    public function testHospitalPivotSupportsConfiguredDimensionsAndMeasures(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/pivot?scope=public&period=all&analysis=hospital_pivot&rows=state&cols=tier&measure=hospital_count',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-analysis-pivot-table-card"]');
        $this->assertSelectorTextContains('[data-testid="stats-analysis-pivot-table-card"]', 'State');
    }

    public function testLegacyAnalysisPivotUrlRedirectsToPivotTables(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(
            Request::METHOD_GET,
            '/statistics/analysis?scope=public&period=all&analysis=allocation_pivot&rows=urgency&cols=gender',
        );

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('/statistics/pivot', $location);
        $this->assertStringContainsString('analysis=allocation_pivot', $location);
    }
}
