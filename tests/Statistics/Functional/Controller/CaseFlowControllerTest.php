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
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\Tests\Support\Statistics\RefreshesStatisticsFunctionalDataTrait;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CaseFlowControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsFunctionalDataTrait;
    use RefreshesStatisticsMaterializedViewsTrait;

    public function testCaseFlowPageShowsAggregatedKpisWithSeededData(): void
    {
        $client = self::createClient();

        $user = UserFactory::createOne(['username' => 'case-flow-ctrl-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'CaseFlowCtrlState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowCtrlDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowCtrlHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowCtrlSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowCtrlDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowCtrlAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowCtrlRaw', 'code' => 912_502]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowCtrlImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(15, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:17:18'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $this->loginAsRoleUser($client);
        $crawler = $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-kpis"]', '15');
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-kpis"]', '100%');
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-kpis"]', '17,3');

        $payloadJson = (string) $crawler->filter('[data-controller="case-flow-charts"]')->attr('data-case-flow-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('system_flow', $payload['mode']);
        self::assertNotEmpty($payload['mapFeatures']);
        self::assertSame('caseflowctrldispatch', $payload['mapFeatures'][0]['geoKey']);
        self::assertArrayHasKey('geographicMap', $payload);
        self::assertContains('originChoropleth', $payload['geographicMap']['layers']);
        self::assertArrayNotHasKey('flowStackedBar', $payload);
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-optgroup-origin"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-transport"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-stacked-bar"]');
    }

    public function testCaseFlowPageIsDisplayedForPublicScope(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-heading-title"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-mode-label"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-kpis"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-map"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-profile"]');
        $this->assertSelectorNotExists('[data-testid="stats-geo-map-segment-dock"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-transport"]');
        $this->assertSelectorExists('[data-testid="stats-geo-map-expand"]');
        $this->assertSelectorExists('[data-testid="stats-geo-map-share-legend"]');
        $this->assertSelectorNotExists('[data-testid="stats-geo-map-layer-origin"]');
        $this->assertSelectorNotExists('[data-testid="stats-geo-map-layer-isochrones"]');
        $this->assertSelectorExists('details summary');
        $this->assertSelectorExists('[data-testid="statistics-filter-department"]');
    }

    public function testStackedBarIsHiddenForPublicScope(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-stacked-bar"]');
    }

    public function testStackedBarIsHiddenForHospitalCohortScope(): void
    {
        $client = $this->createClientAsRoleUser();
        $this->seedEligibleUrbanBasicCohort($client);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=hospital_cohort:urban_basic&period=all',
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-stacked-bar"]');

        $payloadJson = (string) $crawler->filter('[data-controller="case-flow-charts"]')->attr('data-case-flow-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('system_flow', $payload['mode']);
        self::assertArrayNotHasKey('flowStackedBar', $payload);
    }

    public function testCaseFlowPageDoesNotExposeForeignHospitalNames(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/case-flow?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('data-hospital-name', $content);
    }

    public function testRoleUserSeesPublicStateDispatchScopesOnly(): void
    {
        $client = $this->createClientAsRoleUser();
        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=public&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('All assignments', $labels);
        self::assertNotContains('My hospitals', $labels);
        self::assertNotContains('Hospitals', $labels);
    }

    public function testParticipantWithOwnedHospitalsSeesMyHospitalsLabel(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        HospitalFactory::createOne(['owner' => $user]);
        HospitalFactory::createOne(['owner' => $user]);
        $client->loginUser($user);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=my_hospitals&period=all',
        );

        $this->assertResponseIsSuccessful();
        $labels = $this->scopePrimaryMenuLabels($crawler);
        self::assertContains('My hospitals', $labels);
        self::assertSelectorExists('[data-testid="stats-case-flow-origin-bar"]');
        self::assertSelectorNotExists('[data-testid="stats-case-flow-stacked-bar"]');
    }

    public function testInvalidMyHospitalsScopeRedirectsToPublic(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->followRedirects(false);
        $client->request(
            Request::METHOD_GET,
            '/statistics/case-flow?scope=my_hospitals&period=all',
        );

        $this->assertResponseStatusCodeSame(302);
        $location = (string) $client->getResponse()->headers->get('Location');
        self::assertStringContainsString('scope=public', $location);
        self::assertStringNotContainsString('my_hospitals', $location);
    }

    public function testHospitalScopeShowsInflowDiagramWithoutOutflowStage(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CaseFlowHospitalState']);
        $homeArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowHospitalHome', 'state' => $state]);
        $otherArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowHospitalOther', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowHospitalKlinik',
            'state' => $state,
            'dispatchArea' => $homeArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowHospitalSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowHospitalDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowHospitalAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowHospitalRaw', 'code' => 912_503]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowHospitalImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(7, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $homeArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:20:00'),
        ]);
        AllocationFactory::createMany(3, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $otherArea,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 10:25:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(Request::METHOD_GET, '/statistics/case-flow', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-mode-label"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-dispatch-flow"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-dispatch-flow-outflow"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-urgency-structure"]');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-flow-inflow-count"]', '3');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-flow-local-count"]', '7');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-flow-total-count"]', '10');
        $this->assertSelectorNotExists('[data-testid="stats-geo-map-layer-origin"]');
        $this->assertSelectorNotExists('[data-testid="stats-geo-map-layer-isochrones"]');
    }

    public function testDispatchAreaScopeShowsInflowAndOutflowDiagram(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CaseFlowDaState']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'CaseFlowDaHome', 'state' => $state]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'CaseFlowDaOther', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'CaseFlowDaKlinikA',
            'state' => $state,
            'dispatchArea' => $areaA,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
            'latitude' => 51.31,
            'longitude' => 9.49,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'CaseFlowDaKlinikB',
            'state' => $state,
            'dispatchArea' => $areaB,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'owner' => $user,
            'createdBy' => $user,
            'latitude' => 50.80,
            'longitude' => 8.77,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowDaSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowDaDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowDaAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowDaRaw', 'code' => 912_604]);

        $importA = ImportFactory::createOne(['name' => 'CaseFlowDaImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'CaseFlowDaImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createMany(7, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:20:00'),
        ]);
        AllocationFactory::createMany(3, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaB,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-02 10:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 10:25:00'),
        ]);
        AllocationFactory::createMany(4, [
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $areaA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-03 11:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-03 11:22:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        $this->refreshStatisticsMaterializedViews();

        $crawler = $client->request(Request::METHOD_GET, '/statistics/case-flow', [
            'scope' => 'dispatch_area:'.$areaA->getId(),
            'period' => 'all',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-mode-label"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-dispatch-flow"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-dispatch-flow-outflow"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-urgency-structure"]');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-flow-inflow-count"]', '3');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-flow-local-count"]', '11');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-flow-total-count"]', '14');

        $payloadJson = (string) $crawler->filter('[data-controller="case-flow-charts"]')->attr('data-case-flow-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame($areaA->getId(), $payload['geographicMap']['selectedDispatchAreaId']);
        self::assertContains('destinationHospitals', $payload['geographicMap']['compactLayers']);
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-stacked-bar"]');
    }

    public function testHospitalScopeShowsCompactOriginAndIsochroneLayerToggles(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'CaseFlowIsoState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'CaseFlowIsoDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'CaseFlowIsoKlinik',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
        ]);

        SpecialityFactory::createOne(['name' => 'CaseFlowIsoSpec']);
        DepartmentFactory::createOne(['name' => 'CaseFlowIsoDept']);
        AssignmentFactory::createOne(['name' => 'CaseFlowIsoAssign']);
        IndicationRawFactory::createOne(['name' => 'CaseFlowIsoRaw', 'code' => 912_605]);

        $import = ImportFactory::createOne(['name' => 'CaseFlowIsoImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(12, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:12:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $store = self::getContainer()->get(HospitalIsochroneFileStore::class);
        $store->writeForHospital($hospital, [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 600],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.66, 50.09], [8.70, 50.09], [8.70, 50.13], [8.66, 50.13], [8.66, 50.09]]],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'properties' => ['value' => 1200],
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [[[8.62, 50.06], [8.74, 50.06], [8.74, 50.16], [8.62, 50.16], [8.62, 50.06]]],
                    ],
                ],
            ],
        ]);
        $isochronePath = $store->pathFor($hospital);

        try {
            $crawler = $client->request(Request::METHOD_GET, '/statistics/case-flow', [
                'scope' => 'hospital',
                'hospital' => (string) $hospital->getId(),
                'period' => 'all',
            ]);

            $this->assertResponseIsSuccessful();
            $payloadJson = (string) $crawler->filter('[data-controller="case-flow-charts"]')->attr('data-case-flow-charts-payload-value');
            $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
            self::assertContains('originChoropleth', $payload['geographicMap']['compactLayers']);
            self::assertContains('isochroneBands', $payload['geographicMap']['compactLayers']);
            $this->assertSelectorExists('[data-testid="stats-geo-map-layer-origin"][checked]');
            $this->assertSelectorExists('[data-testid="stats-geo-map-layer-isochrones"][checked]');
            $this->assertSelectorNotExists('[data-testid="stats-geo-map-layer-destinations"]');
            $this->assertSelectorNotExists('[data-testid="stats-geo-map-layer-hospital"]');
        } finally {
            if (is_file($isochronePath)) {
                unlink($isochronePath);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function scopePrimaryMenuLabels(Crawler $crawler): array
    {
        return $crawler
            ->filter('[data-testid="stats-analysis-context-scope-group"] option')
            ->each(static fn (Crawler $node): string => trim($node->text()));
    }
}
