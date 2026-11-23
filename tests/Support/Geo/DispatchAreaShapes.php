<?php

declare(strict_types=1);

namespace App\Tests\Support\Geo;

use App\Allocation\Application\Contracts\DispatchAreaGeoIndexInterface;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gives dispatch areas a small hand-written map shape, for tests of pages with a map.
 */
trait DispatchAreaShapes
{
    private function giveDispatchAreasShapes(): void
    {
        $entityManager = $this->shapesEntityManager();
        foreach ($entityManager->getRepository(DispatchArea::class)->findAll() as $index => $area) {
            $name = (string) $area->getName();
            $district = new District(sprintf('06%03d', $index + 1), 'Frankfurt' === $name ? 'Frankfurt am Main' : $name)
                ->setKind('Kreisfreie Stadt')
                ->setDispatchArea($area);
            $geometry = new DistrictGeometry($district);
            $west = 8.0 + 0.1 * $index;
            $geometry->storeGeometry(
                (string) json_encode(['type' => 'Polygon', 'coordinates' => [[[$west, 50.0], [$west + 0.1, 50.0], [$west + 0.1, 50.1], [$west, 50.0]]]]),
                'test',
                null,
                null,
                null,
            );
            $entityManager->persist($district);
            $entityManager->persist($geometry);
        }
        $entityManager->flush();
    }

    private function dispatchAreaKey(string $name): string
    {
        $area = $this->shapesEntityManager()->getRepository(DispatchArea::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(DispatchArea::class, $area);

        return (string) $area->getId();
    }

    /**
     * The join of a map: its key must find a feature of the shapes endpoint.
     */
    private function assertHasMapFeature(string $key): void
    {
        /** @var DispatchAreaGeoIndexInterface $index */
        $index = self::getContainer()->get(DispatchAreaGeoIndexInterface::class);
        /** @var array{features: list<array{properties: array{key: string}}>} $shapes */
        $shapes = json_decode($index->geoJson()->content, true, 512, \JSON_THROW_ON_ERROR);

        self::assertContains($key, array_map(static fn (array $feature): string => $feature['properties']['key'], $shapes['features']));
    }

    private function shapesEntityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
