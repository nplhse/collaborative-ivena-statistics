<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Speciality;
use App\DataFixtures\Generator\Application\Catalog\CatalogLookupType;
use App\DataFixtures\Generator\Application\Catalog\GeneratorCatalog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns the generator's names and PZC codes into references to the installed catalog.
 *
 * Resolution lives in `GeneratorCatalog`: by name key and alias like the import, with the mapping
 * file, and independent of database ids (ADR 022).
 */
final readonly class PatternLookupResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private GeneratorCatalog $catalog,
    ) {
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function reference(string $class, string $name): object
    {
        /** @var T $reference */
        $reference = $this->entityManager->getReference($class, $this->resolveId($class, $name));

        return $reference;
    }

    /**
     * The first row of a type by name and public id.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function referenceAny(string $class): object
    {
        /** @var T $reference */
        $reference = $this->entityManager->getReference($class, $this->catalog->firstId($class));

        return $reference;
    }

    /**
     * @param class-string $class
     */
    public function resolveId(string $class, string $name): int
    {
        return $this->catalog->id(CatalogLookupType::forClass($class), $name);
    }

    /**
     * The speciality a department of the generator is written with.
     */
    public function specialityFor(string $department): Speciality
    {
        /** @var Speciality $speciality */
        $speciality = $this->entityManager->getReference(Speciality::class, $this->specialityIdFor($department));

        return $speciality;
    }

    public function specialityIdFor(string $department): int
    {
        return $this->catalog->specialityIdOf($department);
    }

    /**
     * The normalized indication of a PZC code and a raw indication mapped to it.
     *
     * Keyed by code, not by wording: obstetric codes share their wording (`<16 SSW` is 521, 531 and 541).
     *
     * @param string $code three-digit PZC code
     *
     * @return array{IndicationNormalized, IndicationRaw}
     */
    public function indicationForCode(string $code): array
    {
        $ids = $this->catalog->indicationIds($code);

        /** @var IndicationNormalized $normalized */
        $normalized = $this->entityManager->getReference(IndicationNormalized::class, $ids['normalized']);
        /** @var IndicationRaw $raw */
        $raw = $this->entityManager->getReference(IndicationRaw::class, $ids['raw']);

        return [$normalized, $raw];
    }
}
