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
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\MaterializedView\RefreshesStatisticsMaterializedViewsTrait;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class CaseFlowSegmentProfileControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use RefreshesStatisticsMaterializedViewsTrait;

    public function testHospitalTravelBandUrgencyFrameStaysInsideHospitalScope(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'GeoSegHospitalState']);
        $home = DispatchAreaFactory::createOne(['name' => 'GeoSegHospitalHome', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GeoSegHospitalKlinik',
            'state' => $state,
            'dispatchArea' => $home,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
        ]);

        $this->seedCatalog();
        $import = ImportFactory::createOne(['name' => 'GeoSegHospitalImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(12, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:15:00'),
        ]);
        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::INPATIENT,
            'createdAt' => new \DateTimeImmutable('2026-04-02 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-02 09:05:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $query = [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'geo_segment' => 'travel:10_20',
            'geo_profile' => 'urgency',
        ];

        $crawler = $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', $query);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('turbo-frame#stats-case-flow-segment-profile-frame');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-segment-count"]', '12');
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-group-urgency"]');
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-segment-group-urgency"]', 'Emergency Care');
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-segment-group-urgency"]', 'Inpatient Care');
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-segment-group-urgency"]', 'Outpatient Care');
        $this->assertSelectorTextNotContains('[data-testid="stats-case-flow-segment-group-urgency"]', 'U1');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-group-gender"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-kpis"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-median"]');

        $page = $client->request(Request::METHOD_GET, '/statistics/case-flow', $query);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-profile"]');
        $frameSrc = (string) $page->filter('[data-testid="stats-case-flow-segment-profile-frame"]')->attr('src');
        self::assertStringContainsString('/statistics/case-flow/segment-profile', $frameSrc);
        $frameQuery = [];
        parse_str((string) parse_url($frameSrc, PHP_URL_QUERY), $frameQuery);
        self::assertSame('travel:10_20', $frameQuery['geo_segment'] ?? null);
        self::assertSame('urgency', $frameQuery['geo_profile'] ?? null);
        $urgencyTabHref = (string) $page->filter('[data-testid="stats-case-flow-segment-tab-urgency"]')->attr('href');
        self::assertStringContainsString('geo_profile=urgency', $urgencyTabHref);
        self::assertSame(
            'stats-case-flow-segment-profile-frame',
            $page->filter('[data-testid="stats-case-flow-segment-tab-urgency"]')->attr('data-turbo-frame'),
        );
        $payloadJson = (string) $page->filter('[data-controller="case-flow-charts"]')->attr('data-case-flow-charts-payload-value');
        $payload = json_decode(html_entity_decode($payloadJson, ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['type' => 'travel_time_band', 'id' => '10_20'], $payload['geographicMap']['selectedSegment']);
    }

    public function testDispatchAreaOriginUsesCatchmentAndKeepsDrawerInFrameUrl(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'GeoSegDaState']);
        $areaA = DispatchAreaFactory::createOne(['name' => 'GeoSegDaHome', 'state' => $state]);
        $areaB = DispatchAreaFactory::createOne(['name' => 'GeoSegDaOther', 'state' => $state]);
        $hospitalA = HospitalFactory::createOne([
            'name' => 'GeoSegDaKlinikA',
            'state' => $state,
            'dispatchArea' => $areaA,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
        ]);
        $hospitalB = HospitalFactory::createOne([
            'name' => 'GeoSegDaKlinikB',
            'state' => $state,
            'dispatchArea' => $areaB,
            'tier' => HospitalTier::BASIC,
            'location' => HospitalLocation::RURAL,
            'owner' => $user,
            'createdBy' => $user,
        ]);

        $this->seedCatalog();
        $importA = ImportFactory::createOne(['name' => 'GeoSegDaImportA', 'hospital' => $hospitalA, 'createdBy' => $user]);
        $importB = ImportFactory::createOne(['name' => 'GeoSegDaImportB', 'hospital' => $hospitalB, 'createdBy' => $user]);

        AllocationFactory::createMany(12, [
            'import' => $importA,
            'hospital' => $hospitalA,
            'state' => $state,
            'dispatchArea' => $areaA,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
        ]);
        AllocationFactory::createMany(4, [
            'import' => $importB,
            'hospital' => $hospitalB,
            'state' => $state,
            'dispatchArea' => $areaA,
            'gender' => AllocationGender::FEMALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-02 09:00:00'),
        ]);

        $rebuilder = self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class);
        $rebuilder->rebuildForImport($importA->getId());
        $rebuilder->rebuildForImport($importB->getId());
        $this->refreshStatisticsMaterializedViews();

        $query = [
            'scope' => 'dispatch_area:'.$areaA->getId(),
            'period' => 'all',
            'geo_segment' => 'origin:'.$areaA->getId(),
            'geo_profile' => 'overview',
            'urgency' => '1',
        ];

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', $query);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-segment-count"]', '12');
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-overview"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-group-urgency"]');

        $page = $client->request(Request::METHOD_GET, '/statistics/case-flow', $query);
        $this->assertResponseIsSuccessful();
        $picker = $page->filter('[data-testid="stats-case-flow-segment-picker"]');
        self::assertSame('origin:'.$areaA->getId(), $picker->filter('option[selected]')->attr('value'));
        $frameSrc = (string) $page->filter('[data-testid="stats-case-flow-segment-profile-frame"]')->attr('src');
        $frameQuery = [];
        parse_str((string) parse_url($frameSrc, PHP_URL_QUERY), $frameQuery);
        self::assertSame('1', (string) ($frameQuery['urgency'] ?? ''));
        self::assertSame('origin:'.$areaA->getId(), $frameQuery['geo_segment'] ?? null);

        $publicHref = (string) $page->filter('.page-header .dropdown-menu .dropdown-item')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Public'),
        )->attr('href');
        self::assertStringContainsString('scope=public', $publicHref);
        self::assertStringNotContainsString('geo_segment', $publicHref);
        self::assertStringNotContainsString('geo_profile', $publicHref);
    }

    public function testEntireAreaFrameShowsPopulationWhenNoSegmentIsSelected(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'GeoSegEntireState']);
        $home = DispatchAreaFactory::createOne(['name' => 'GeoSegEntireHome', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GeoSegEntireKlinik',
            'state' => $state,
            'dispatchArea' => $home,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
        ]);

        $this->seedCatalog();
        $import = ImportFactory::createOne(['name' => 'GeoSegEntireImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(12, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $home,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
            'arrivalAt' => new \DateTimeImmutable('2026-04-01 09:15:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'geo_profile' => 'urgency',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-segment-label"]', 'Entire area');
        $this->assertSelectorTextSame('[data-testid="stats-case-flow-segment-count"]', '12');
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-median"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-share"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-group-urgency"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-empty"]');

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'geo_profile' => 'demographics',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-group-gender"]');
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-group-age"]');

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'geo_profile' => 'resources',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-group-resources"]');

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'geo_segment' => 'origin:not-a-number',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('[data-testid="stats-case-flow-segment-label"]', 'Entire area');
    }

    public function testEmptyAndSuppressedPopulationsHideDimensionRows(): void
    {
        $client = self::createClient();
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'GeoSegEmptyState']);
        $area = DispatchAreaFactory::createOne(['name' => 'GeoSegEmptyHome', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'GeoSegEmptyKlinik',
            'state' => $state,
            'dispatchArea' => $area,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
        ]);

        $this->seedCatalog();
        $import = ImportFactory::createOne(['name' => 'GeoSegEmptyImport', 'hospital' => $hospital, 'createdBy' => $user]);
        AllocationFactory::createMany(5, [
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $area,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'createdAt' => new \DateTimeImmutable('2026-04-01 09:00:00'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-suppressed"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-overview"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-empty"]');

        $client->request(Request::METHOD_GET, '/statistics/case-flow/segment-profile', [
            'scope' => 'hospital',
            'hospital' => (string) $hospital->getId(),
            'period' => 'all',
            'geo_segment' => 'origin:'.$area->getId(),
            'geo_profile' => 'demographics',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-case-flow-segment-suppressed"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-group-gender"]');
        $this->assertSelectorNotExists('[data-testid="stats-case-flow-segment-count"]');
    }

    private function seedCatalog(): void
    {
        SpecialityFactory::createOne(['name' => 'GeoSegSpec-'.bin2hex(random_bytes(2))]);
        DepartmentFactory::createOne(['name' => 'GeoSegDept-'.bin2hex(random_bytes(2))]);
        AssignmentFactory::createOne(['name' => 'GeoSegAssign-'.bin2hex(random_bytes(2))]);
        IndicationRawFactory::createOne(['name' => 'GeoSegRaw-'.bin2hex(random_bytes(2)), 'code' => random_int(912_800, 912_899)]);
    }
}
