<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Infrastructure\Faker\Provider\HesseDistrictPolygons;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Stores the coarse demo shapes for the synthetic districts, offline and only from fixtures (ADR 041).
 * It is no production seed. A district that already has a geometry keeps it.
 */
final readonly class DemoDistrictGeometries
{
    public const string PROVIDER = 'demo';

    public function __construct(
        private EntityManagerInterface $entityManager,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    public function store(): void
    {
        /** @var array{licence: string, attribution: string, features: list<array{properties: array{ags: string}, geometry: array<string, mixed>}>} $data */
        $data = json_decode((string) file_get_contents($this->projectDir.'/'.HesseDistrictPolygons::GEOJSON), true, 512, \JSON_THROW_ON_ERROR);

        $districts = [];
        foreach ($this->entityManager->getRepository(District::class)->findAll() as $district) {
            $districts[$district->getAgs()] = $district;
        }

        foreach ($data['features'] as $feature) {
            $ags = $feature['properties']['ags'];
            $district = $districts[$ags] ?? throw new \RuntimeException(sprintf('The demo shape %s has no district.', $ags));

            $geometry = $this->entityManager->find(DistrictGeometry::class, $district->getId());
            if ($geometry instanceof DistrictGeometry && null !== $geometry->getGeometry()) {
                continue;
            }

            if (!$geometry instanceof DistrictGeometry) {
                $geometry = new DistrictGeometry($district);
                $this->entityManager->persist($geometry);
            }

            $geometry->storeGeometry(
                json_encode($feature['geometry'], \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION),
                self::PROVIDER,
                $data['licence'],
                $data['attribution'],
                null,
            );
        }

        $this->entityManager->flush();
    }
}
