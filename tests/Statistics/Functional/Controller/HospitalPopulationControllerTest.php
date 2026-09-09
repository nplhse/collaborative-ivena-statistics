<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalPopulationControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testDefaultUrlRendersParticipationSection(): void
    {
        $client = self::createClient();
        $this->seedHospitals();
        $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/statistics/hospital-population');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-hospital-population-tabs"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-tab-participation"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-tab-coverage"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-tab-beds"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-tab-allocations"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-participation"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-dispatch-areas-kpi"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-map"]');
        $this->assertSelectorExists('.hospital-population-map-frame');
        $this->assertSelectorNotExists('.case-flow-map-square');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-regional-coverage"]');
        $this->assertSelectorExists('[data-sort="sort-dispatch-area"]');
        $this->assertSelectorNotExists('[data-sort="sort-state"]');
        $this->assertSelectorNotExists('.sort-state');
        self::assertSame(
            ['Dispatch area', 'All', 'Participants', 'Coverage'],
            $crawler->filter('[data-testid="stats-hospital-population-regional-coverage"] thead th')->each(
                static fn ($node): string => trim($node->text()),
            ),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-hospital-population-map"]')->closest('.col-lg-6')->count(),
        );
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-hospital-population-regional-coverage"]')->closest('.col-lg-6')->count(),
        );
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-coverage"]');
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-beds"]');
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-allocation-basis"]');
        self::assertSame('page', $crawler->filter('[data-testid="stats-hospital-population-tab-participation"]')->attr('aria-current'));

        $client->request(Request::METHOD_GET, '/statistics/hospital-population/participation');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-hospital-population-participation"]');
    }

    public function testCoverageSectionRendersRepresentativityTables(): void
    {
        $client = self::createClient();
        $this->seedHospitals();
        $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/statistics/hospital-population/coverage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-hospital-population-coverage"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-coverage-tier"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-coverage-size"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-coverage-location"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-coverage-state"]');
        self::assertGreaterThan(
            0,
            $crawler->filter('[data-testid="stats-hospital-population-coverage-tier"]')->closest('.col-12')->count(),
        );
        self::assertSame(
            [
                'Coverage by location and tier',
                'Coverage by size and tier',
                'Distribution by tier',
            ],
            array_slice(
                $crawler->filter('[data-testid="stats-hospital-population-coverage"] .card-title')->each(
                    static fn ($node): string => trim($node->text()),
                ),
                0,
                3,
            ),
        );
        $this->assertSelectorTextNotContains(
            '[data-testid="stats-hospital-population-coverage"]',
            'Positive delta indicates overrepresentation',
        );
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-participation"]');
        $this->assertSelectorNotExists('[data-controller="hospital-population-charts"]');
        self::assertSame('page', $crawler->filter('[data-testid="stats-hospital-population-tab-coverage"]')->attr('aria-current'));
    }

    public function testBedsSectionRendersMatrixAndBoxPlots(): void
    {
        $client = self::createClient();
        $this->seedHospitals();
        $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/statistics/hospital-population/beds');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-hospital-population-beds"]');
        $this->assertSelectorExists('[data-testid="stats-hospital-population-beds-matrix"]');
        self::assertSame(
            [
                'Beds by tier',
                'Beds by location',
                'Beds – descriptive statistics',
            ],
            $crawler->filter('[data-testid="stats-hospital-population-beds"] .card-title')->each(
                static fn ($node): string => trim($node->text()),
            ),
        );
        $this->assertSelectorExists('[data-controller="hospital-population-charts"]');
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-allocation-basis"]');
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-participation"]');
        self::assertSame('page', $crawler->filter('[data-testid="stats-hospital-population-tab-beds"]')->attr('aria-current'));
    }

    public function testAllocationsSectionRendersChartsAndCrossTables(): void
    {
        $client = self::createClient();
        $this->seedHospitals();
        $this->loginAsRoleUser($client);

        $crawler = $client->request(Request::METHOD_GET, '/statistics/hospital-population/allocations');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-hospital-population-allocation-basis"]');
        self::assertNull($crawler->filter('[data-testid="stats-hospital-population-allocation-basis"]')->attr('class'));
        $this->assertSelectorTextContains(
            '[data-testid="stats-hospital-population-allocation-basis"]',
            'Allocations by tier',
        );
        $this->assertSelectorTextNotContains(
            '[data-testid="stats-hospital-population-allocation-basis"]',
            'Descriptive summary of allocations per participating hospital',
        );
        $this->assertSelectorExists('[data-controller="hospital-population-charts"]');
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-beds"]');
        $this->assertSelectorNotExists('[data-testid="stats-hospital-population-participation"]');
        self::assertSame('page', $crawler->filter('[data-testid="stats-hospital-population-tab-allocations"]')->attr('aria-current'));
    }

    public function testLegacyCharacteristicsUrlRedirectsToBeds(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_GET, '/statistics/hospital-population/characteristics');

        $this->assertResponseRedirects('/statistics/hospital-population/beds');
    }

    public function testUnknownSectionReturnsNotFound(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);

        $client->request(Request::METHOD_GET, '/statistics/hospital-population/foobar');

        $this->assertResponseStatusCodeSame(404);
    }

    private function seedHospitals(): void
    {
        $state = StateFactory::createOne(['name' => 'HpCtrlState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'HpCtrlDispatch', 'state' => $state]);
        HospitalFactory::createOne([
            'name' => 'HpCtrlParticipant',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'size' => HospitalSize::LARGE,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'beds' => 400,
            'isParticipating' => true,
        ]);
        HospitalFactory::createOne([
            'name' => 'HpCtrlReference',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'size' => HospitalSize::SMALL,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'beds' => 80,
            'isParticipating' => false,
        ]);
    }
}
