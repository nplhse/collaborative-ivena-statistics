<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalLocation;
use App\Allocation\Domain\Enum\HospitalSize;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Allocation\Infrastructure\Factory\AddressFactory;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Statistics\Application\Contract\AllocationStatsProjectionRebuildInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowHospitalControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testShowDisplaysHospitalDetails(): void
    {
        $client = $this->createClientAsParticipant();

        $owner = UserFactory::createOne(['username' => 'owner-user']);
        $createdBy = UserFactory::createOne(['username' => 'area-user']);
        $stateName = 'Hessen';
        $state = StateFactory::createOne(['name' => $stateName]);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $address = AddressFactory::new([
            'street' => 'Fake Street 123',
            'postalCode' => '12345',
            'city' => 'Teststadt',
            'state' => $stateName,
            'country' => 'DE',
        ])->create();

        $hospital = HospitalFactory::createOne([
            'name' => 'St. Test Hospital',
            'beds' => 321,
            'address' => $address,
            'owner' => $owner,
            'createdBy' => $createdBy,
            'state' => $state,
            'dispatchArea' => $dispatch,
            'latitude' => 50.1109,
            'longitude' => 8.6821,
            'location' => HospitalLocation::cases()[0],
            'size' => HospitalSize::cases()[0],
            'tier' => HospitalTier::cases()[0],
            'createdAt' => new \DateTimeImmutable('2025-01-02 03:04:05'),
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('#hospital-name', 'St. Test Hospital');
        self::assertSelectorTextContains('#hospital-created-by', 'area-user');
        self::assertSelectorTextContains('#hospital-owned-by', 'owner-user');
        self::assertSelectorTextContains('#hospital-created-at', '02.01.2025');
        self::assertSelectorTextContains('#hospital-address', 'Teststadt');
        self::assertSelectorTextContains('#hospital-address', $stateName);
        self::assertSelectorTextContains('#hospital-size', HospitalSize::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-beds', '321');
        self::assertSelectorTextContains('#hospital-location', HospitalLocation::cases()[0]->value);
        self::assertSelectorTextContains('#hospital-tier', HospitalTier::cases()[0]->value);
        self::assertSelectorExists('a[href="/explore/dispatch_area/'.$dispatch->getPublicIdString().'"]');
        self::assertSelectorExists('a[href="/explore/state/'.$state->getPublicIdString().'"]');
        self::assertSelectorNotExists('a.btn-primary[href="/hospitals/'.$hospital->getId().'/edit"]');
        self::assertSelectorExists('[data-testid="catalog-orientation-map"]');
        $map = $crawler->filter('[data-testid="catalog-orientation-map"]');
        self::assertSame('frankfurt', $map->attr('data-catalog-orientation-map-highlight-key-value'));
        self::assertSame('50.1109', $map->attr('data-catalog-orientation-map-marker-lat-value'));
        self::assertSame('8.6821', $map->attr('data-catalog-orientation-map-marker-lng-value'));
        self::assertSame('false', $map->attr('data-catalog-orientation-map-show-route-value'));
        self::assertNull($map->attr('data-catalog-orientation-map-isochrones-value'));
        self::assertSelectorExists('[data-testid="catalog-actions"]');
        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertContains('/explore/dispatch_area/'.$dispatch->getPublicIdString(), $actionHrefs);
        self::assertNotContains('/explore/allocation?hospitalFilter='.$hospital->getId(), $actionHrefs);
        self::assertNotContains('/import?hospitalId='.$hospital->getId(), $actionHrefs);
    }

    public function testOwnerSeesEditButtonOnOwnHospitalShowPage(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'owner-user']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Owned Clinic']);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.btn-primary[href="/hospitals/'.$hospital->getId().'/edit"]');

        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertContains('/explore/allocation?hospitalFilter='.$hospital->getId(), $actionHrefs);
        self::assertContains('/import?hospitalId='.$hospital->getId(), $actionHrefs);
        self::assertTrue($this->containsBenchmarkingHospitalScope($actionHrefs, (int) $hospital->getId()));
    }

    public function testOwnerYearHeatmapLinksToHospitalFilteredAllocationList(): void
    {
        $client = self::createClient();
        $hospital = $this->seedHospitalWithYearAllocations('year-owner', 'Year Clinic');
        $owner = $hospital->getOwner();
        self::assertNotNull($owner);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="catalog-coverage-year-link"]');

        $href = $crawler->filter('[data-testid="catalog-coverage-year-link"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('hospitalFilter='.$hospital->getId(), $href);
        self::assertStringContainsString('createdFrom=2024-01-01T00:00:00', $href);
        self::assertStringContainsString('createdToExclusive=2025-01-01T00:00:00', $href);
    }

    public function testParticipantDoesNotSeeHospitalYearDrillDownLinks(): void
    {
        $client = $this->createClientAsParticipant();
        $hospital = $this->seedHospitalWithYearAllocations('hidden-year-owner', 'Hidden Year Clinic');

        $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="catalog-coverage-year-heatmap"]');
        self::assertSelectorExists('.catalog-year-heatmap-cell.is-sensitive');
        self::assertSelectorNotExists('[data-testid="catalog-coverage-year-link"]');
    }

    public function testAdminSeesEditButtonOnForeignHospitalShowPage(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner, 'name' => 'Foreign Clinic']);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('a.btn-primary[href="/hospitals/'.$hospital->getId().'/edit"]');
    }

    public function testShowRejectsPostMethod(): void
    {
        $client = $this->createClientAsParticipant();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Dispatch Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Post guard hospital',
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);

        $client->request(Request::METHOD_POST, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseStatusCodeSame(405);
    }

    public function testShow404ForUnknownHospital(): void
    {
        $client = $this->createClientAsParticipant();
        $client->request(Request::METHOD_GET, '/explore/hospital/00000000-0000-4000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    private function seedHospitalWithYearAllocations(string $ownerUsername, string $hospitalName): Hospital
    {
        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => $ownerUsername]);
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $dispatch = DispatchAreaFactory::createOne(['name' => 'Frankfurt', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => $hospitalName,
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => $dispatch,
        ]);
        $import = ImportFactory::createOne(['hospital' => $hospital, 'name' => $hospitalName.' Import']);
        AssignmentFactory::createOne(['name' => $hospitalName.' Assignment']);
        DepartmentFactory::createOne(['name' => $hospitalName.' Department']);
        SpecialityFactory::createOne(['name' => $hospitalName.' Speciality']);
        OccasionFactory::createOne(['name' => $hospitalName.' Occasion']);
        IndicationRawFactory::createOne(['name' => $hospitalName.' Indication']);
        IndicationNormalizedFactory::createOne(['name' => $hospitalName.' Indication']);

        for ($i = 0; $i < 5; ++$i) {
            AllocationFactory::createOne([
                'hospital' => $hospital,
                'import' => $import,
                'state' => $state,
                'dispatchArea' => $dispatch,
                'createdAt' => new \DateTimeImmutable('2024-03-10 08:00:00'),
                'arrivalAt' => new \DateTimeImmutable('2024-03-10 08:20:00'),
            ]);
        }

        $importId = $import->getId();
        self::assertNotNull($importId);
        self::getContainer()->get(AllocationStatsProjectionRebuildInterface::class)->rebuildForImport($importId);

        return $hospital;
    }

    /**
     * @param list<string> $hrefs
     */
    private function containsBenchmarkingHospitalScope(array $hrefs, int $hospitalId): bool
    {
        foreach ($hrefs as $href) {
            $parts = parse_url($href);
            if (!\is_array($parts) || !str_contains((string) ($parts['path'] ?? ''), '/statistics/benchmarking')) {
                continue;
            }

            parse_str((string) ($parts['query'] ?? ''), $query);
            if (
                'hospital' === ($query['scope'] ?? null)
                && (string) $hospitalId === (string) ($query['hospital'] ?? '')
                && 'all' === ($query['period'] ?? null)
                && 'hospital_cohort' === ($query['comparison_scope'] ?? null)
                && 'all_time' === ($query['comparison_period'] ?? null)
            ) {
                return true;
            }
        }

        return false;
    }
}
