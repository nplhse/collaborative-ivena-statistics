<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Functional\Controller;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Geo\HospitalIsochroneFileStore;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IsochroneOriginHeatmapControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testOverviewOmitsFrameOutsideHospitalScope(): void
    {
        $client = $this->createClientAsRoleUser();
        $client->request(Request::METHOD_GET, '/statistics/?scope=public&period=all');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-isochrone-origin-map-frame"]');
        $this->assertSelectorNotExists('[data-testid="stats-isochrone-origin-map"]');
    }

    public function testOverviewEmbedsLazyFrameForHospitalScope(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: false);

        $crawler = $client->request(Request::METHOD_GET, '/statistics/', [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map-frame"]');
        $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map-placeholder"]');
        $this->assertSelectorNotExists('[data-testid="stats-isochrone-origin-map"]');
        $src = (string) $crawler->filter('[data-testid="stats-isochrone-origin-map-frame"]')->attr('src');
        self::assertStringContainsString('/statistics/widgets/isochrone-origin-map', $src);
        self::assertStringContainsString('scope=hospital', $src);
        self::assertStringContainsString('hospital='.$fixture['hospitalId'], $src);
    }

    public function testWidgetReturnsEmptyFrameWithoutIsochrones(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: false);

        $client->request(Request::METHOD_GET, '/statistics/widgets/isochrone-origin-map', [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('[data-testid="stats-isochrone-origin-map"]');
        $this->assertSelectorNotExists('[data-testid="stats-isochrone-origin-map-placeholder"]');
    }

    public function testWidgetRendersMapWhenIsochronesExist(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: true);

        try {
            $client->request(Request::METHOD_GET, '/statistics/widgets/isochrone-origin-map', [
                'scope' => 'hospital',
                'hospital' => (string) $fixture['hospitalId'],
                'period' => 'all',
            ]);

            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map"]');
            $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map-legend"]');
            $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map-unmapped"]');
        } finally {
            $this->removeIsochroneFile($fixture['isochronePath']);
        }
    }

    public function testIndicationDashboardFrameKeepsIndicationFilter(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: false);

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/indication/'.$fixture['indicationId'],
            [
                'scope' => 'hospital',
                'hospital' => (string) $fixture['hospitalId'],
                'period' => 'all',
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map-frame"]');
        $src = (string) $crawler->filter('[data-testid="stats-isochrone-origin-map-frame"]')->attr('src');
        self::assertStringContainsString('/statistics/widgets/isochrone-origin-map', $src);
        self::assertStringContainsString('indicationId='.$fixture['indicationId'], $src);
    }

    public function testWidgetAppliesIndicationFilterWhenIsochronesExist(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: true);

        try {
            $client->request(Request::METHOD_GET, '/statistics/widgets/isochrone-origin-map', [
                'scope' => 'hospital',
                'hospital' => (string) $fixture['hospitalId'],
                'period' => 'all',
                'indicationId' => (string) $fixture['indicationId'],
            ]);

            $this->assertResponseIsSuccessful();
            $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map"]');
        } finally {
            $this->removeIsochroneFile($fixture['isochronePath']);
        }
    }

    public function testWidgetWithUnknownIndicationStillSucceeds(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: true);

        try {
            $client->request(Request::METHOD_GET, '/statistics/widgets/isochrone-origin-map', [
                'scope' => 'hospital',
                'hospital' => (string) $fixture['hospitalId'],
                'period' => 'all',
                'indicationId' => '999999',
            ]);

            $this->assertResponseIsSuccessful();

            $client->request(Request::METHOD_GET, '/statistics/widgets/isochrone-origin-map', [
                'scope' => 'hospital',
                'hospital' => (string) $fixture['hospitalId'],
                'period' => 'all',
                'groupId' => '999999',
            ]);

            $this->assertResponseIsSuccessful();
        } finally {
            $this->removeIsochroneFile($fixture['isochronePath']);
        }
    }

    public function testGroupDashboardFrameKeepsGroupFilter(): void
    {
        $client = self::createClient();
        $fixture = $this->seedHospitalFixture($client, writeIsochrones: false);
        $hospital = HospitalFactory::find($fixture['hospitalId']);
        $indication = IndicationNormalizedFactory::find($fixture['indicationId']);
        $group = IndicationGroupFactory::createOne([
            'name' => 'IsoMap Group',
            'createdBy' => $hospital->getCreatedBy(),
        ]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $crawler = $client->request(
            Request::METHOD_GET,
            '/statistics/indication-group/'.$group->getId(),
            [
                'scope' => 'hospital',
                'hospital' => (string) $fixture['hospitalId'],
                'period' => 'all',
            ],
        );

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('[data-testid="stats-isochrone-origin-map-frame"]');
        $src = (string) $crawler->filter('[data-testid="stats-isochrone-origin-map-frame"]')->attr('src');
        self::assertStringContainsString('/statistics/widgets/isochrone-origin-map', $src);
        self::assertStringContainsString('groupId='.$group->getId(), $src);

        $client->request(Request::METHOD_GET, '/statistics/widgets/isochrone-origin-map', [
            'scope' => 'hospital',
            'hospital' => (string) $fixture['hospitalId'],
            'period' => 'all',
            'groupId' => (string) $group->getId(),
        ]);
        $this->assertResponseIsSuccessful();
    }

    /**
     * @return array{hospitalId: int, indicationId: int, isochronePath: ?string}
     */
    private function seedHospitalFixture(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, bool $writeIsochrones): array
    {
        $user = UserFactory::new()->asAdmin()->create();
        $client->loginUser($user);

        $state = StateFactory::createOne(['name' => 'IsoMapState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'IsoMapDispatch', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'IsoMapHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'tier' => HospitalTier::FULL,
            'location' => HospitalLocation::URBAN,
            'owner' => $user,
            'createdBy' => $user,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
        ]);

        SpecialityFactory::createOne(['name' => 'IsoMapSpec']);
        DepartmentFactory::createOne(['name' => 'IsoMapDept']);
        AssignmentFactory::createOne(['name' => 'IsoMapAssign']);
        IndicationRawFactory::createOne(['name' => 'IsoMapRaw', 'code' => 912_361]);
        $indication = IndicationNormalizedFactory::createOne(['name' => 'IsoMap Indication', 'code' => 2001]);
        $import = ImportFactory::createOne(['name' => 'IsoMapImport', 'hospital' => $hospital, 'createdBy' => $user]);

        $created = new \DateTimeImmutable('2026-04-01 08:00:00');
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 40,
            'indicationNormalized' => $indication,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+8 minutes'),
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 41,
            'indicationNormalized' => $indication,
            'createdAt' => $created,
            'arrivalAt' => $created,
        ]);
        AllocationFactory::createOne([
            'import' => $import,
            'hospital' => $hospital,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
            'gender' => AllocationGender::MALE,
            'urgency' => AllocationUrgency::EMERGENCY,
            'age' => 42,
            'indicationNormalized' => $indication,
            'createdAt' => $created,
            'arrivalAt' => $created->modify('+55 minutes'),
        ]);

        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($import->getId());

        $isochronePath = null;
        if ($writeIsochrones) {
            $store = self::getContainer()->get(HospitalIsochroneFileStore::class);
            $store->writeForHospital($hospital, $this->catalogGeoJson());
            $isochronePath = $store->pathFor($hospital);
        }

        return [
            'hospitalId' => $hospital->getId(),
            'indicationId' => $indication->getId(),
            'isochronePath' => $isochronePath,
        ];
    }

    /**
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function catalogGeoJson(): array
    {
        return [
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
        ];
    }

    private function removeIsochroneFile(?string $path): void
    {
        if (null === $path || !is_file($path)) {
            return;
        }

        unlink($path);
    }
}
