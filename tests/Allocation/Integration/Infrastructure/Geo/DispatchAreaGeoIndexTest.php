<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Infrastructure\Geo;

use App\Allocation\Application\Contracts\DispatchAreaGeoIndexInterface;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DispatchAreaGeoIndexTest extends KernelTestCase
{
    use Factories;

    private const array SQUARE_A = [[[8.0, 50.0], [8.1, 50.0], [8.1, 50.1], [8.0, 50.1], [8.0, 50.0]]];
    private const array SQUARE_B = [[[8.1, 50.0], [8.2, 50.0], [8.2, 50.1], [8.1, 50.1], [8.1, 50.0]]];

    public function testAnAreaWithSeveralDistrictsIsOneFeatureWithAllPolygons(): void
    {
        self::bootKernel();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Kassel', 'state' => $state]);
        HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $area]);
        $this->district('06611', 'Kassel Stadt', $area, ['type' => 'Polygon', 'coordinates' => self::SQUARE_A], '© BKG (2023)');
        $this->district('06633', 'Kassel Land', $area, ['type' => 'MultiPolygon', 'coordinates' => [self::SQUARE_B]], '© BKG (2025)');

        $features = $this->features();

        self::assertCount(1, $features);
        self::assertSame((string) $area->getId(), $features[0]['properties']['key']);
        self::assertSame('Hessen', $features[0]['properties']['state']);
        self::assertFalse($features[0]['properties']['neighbour']);
        self::assertSame(['Kassel Stadt', 'Kassel Land'], $features[0]['properties']['districts']);
        self::assertSame(['© BKG (2023)', '© BKG (2025)'], $features[0]['properties']['attributions']);
        self::assertSame('MultiPolygon', $features[0]['geometry']['type']);
        self::assertEquals([self::SQUARE_A, self::SQUARE_B], $features[0]['geometry']['coordinates']);
    }

    public function testAnAreaWithoutAHospitalIsANeighbour(): void
    {
        self::bootKernel();
        $area = DispatchAreaFactory::createOne(['name' => 'Eichsfeld', 'state' => StateFactory::createOne(['name' => 'Thüringen'])]);
        $this->district('16061', 'Eichsfeld', $area, ['type' => 'Polygon', 'coordinates' => self::SQUARE_A]);

        self::assertTrue($this->features()[0]['properties']['neighbour']);
    }

    public function testAMissingOrBrokenGeometryHidesTheWholeAreaUntilItIsStored(): void
    {
        self::bootKernel();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Offenbach', 'state' => $state]);
        DispatchAreaFactory::createOne(['name' => 'Without districts', 'state' => $state]);
        $this->district('06413', 'Offenbach Stadt', $area, ['type' => 'Polygon', 'coordinates' => self::SQUARE_A]);
        $missing = $this->district('06438', 'Offenbach Land', $area, null);
        $key = (string) $area->getId();

        self::assertSame([], $this->features(), 'one of two districts has no geometry');
        self::assertFalse($this->index()->snapshot()->hasShape($key));

        $geometry = new DistrictGeometry($missing);
        $geometry->storeGeometry('{"type":"Polygon","coordinates":[[[1,1]]]}', 'test', null, null, null);
        $this->em()->persist($geometry);
        $this->em()->flush();
        self::assertSame([], $this->features(), 'a broken geometry does not count');

        $geometry->storeGeometry((string) json_encode(['type' => 'Polygon', 'coordinates' => self::SQUARE_B]), 'test', null, null, null);
        $this->em()->flush();

        self::assertCount(1, $this->features(), 'a saved geometry shows without clearing a cache');
        self::assertTrue($this->index()->snapshot()->hasShape($key));
        self::assertSame('Offenbach Stadt, Offenbach Land', $this->index()->snapshot()->districtLabel($key));
        self::assertSame('Hessen', $this->index()->snapshot()->stateWithShapes('hessen'));
        self::assertNull($this->index()->snapshot()->stateWithShapes('Bayern'));
    }

    public function testACommittedChangeOfTheAssignmentChangesTheEtag(): void
    {
        self::bootKernel();
        $area = DispatchAreaFactory::createOne(['name' => 'Fulda', 'state' => StateFactory::createOne(['name' => 'Hessen'])]);
        $district = $this->district('06631', 'Fulda', $area, ['type' => 'Polygon', 'coordinates' => self::SQUARE_A]);
        $before = $this->index()->geoJson()->etag;
        self::assertSame($before, $this->index()->geoJson()->etag);

        $district->setDispatchArea(null);
        $this->em()->flush();

        self::assertNotSame($before, $this->index()->geoJson()->etag);
        self::assertSame([], $this->features());
    }

    /**
     * @param array<string, mixed>|null $geometry
     */
    private function district(string $ags, string $name, DispatchArea $area, ?array $geometry, ?string $attribution = null): District
    {
        $district = new District($ags, $name)->setDispatchArea($area);
        $this->em()->persist($district);
        if (null !== $geometry) {
            $row = new DistrictGeometry($district);
            $row->storeGeometry((string) json_encode($geometry), 'test', null, $attribution, null);
            $this->em()->persist($row);
        }
        $this->em()->flush();

        return $district;
    }

    /**
     * @return list<array{properties: array<string, mixed>, geometry: array<string, mixed>}>
     */
    private function features(): array
    {
        /** @var array{features: list<array{properties: array<string, mixed>, geometry: array<string, mixed>}>} $data */
        $data = json_decode($this->index()->geoJson()->content, true, 512, \JSON_THROW_ON_ERROR);

        return $data['features'];
    }

    private function index(): DispatchAreaGeoIndexInterface
    {
        /** @var DispatchAreaGeoIndexInterface $index */
        $index = self::getContainer()->get(DispatchAreaGeoIndexInterface::class);

        return $index;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
