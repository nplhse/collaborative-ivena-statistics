<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Entity\IndicationRaw;
use Doctrine\ORM\EntityManagerInterface;

final class PatternLookupResolver
{
    /** @var array<class-string, array<string, int>> */
    private array $idsByClassAndName = [];

    /** @var array<string, int> normalized indication name → id of a raw indication mapped to it */
    private array $rawIdsByNormalizedName = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
        $id = $this->resolveId($class, $name);

        /** @var T $reference */
        $reference = $this->entityManager->getReference($class, $id);

        return $reference;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function referenceAny(string $class): object
    {
        return $this->reference($class, $this->anyName($class));
    }

    /**
     * @param class-string $class
     */
    public function resolveId(string $class, string $name): int
    {
        if (!isset($this->idsByClassAndName[$class])) {
            $this->warmUp($class);
        }

        if (!isset($this->idsByClassAndName[$class][$name])) {
            throw new \RuntimeException(sprintf('Unknown lookup reference "%s" for %s.', $name, $class));
        }

        return $this->idsByClassAndName[$class][$name];
    }

    public function referenceIndicationRawForNormalized(string $normalizedName): IndicationRaw
    {
        if ([] === $this->rawIdsByNormalizedName) {
            // One query instead of two per sampled allocation.
            /** @var list<array{name: string, raw_id: int}> $rows */
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT n.name AS name, MIN(r.id) AS raw_id FROM indication_normalized n JOIN indication_raw r ON r.normalized_id = n.id GROUP BY n.name',
            );
            foreach ($rows as $row) {
                $this->rawIdsByNormalizedName[$row['name']] = (int) $row['raw_id'];
            }
        }

        $rawId = $this->rawIdsByNormalizedName[$normalizedName] ?? null;
        if (null === $rawId) {
            return $this->reference(IndicationRaw::class, $this->anyName(IndicationRaw::class));
        }

        /** @var IndicationRaw $reference */
        $reference = $this->entityManager->getReference(IndicationRaw::class, $rawId);

        return $reference;
    }

    /**
     * @param class-string $class
     */
    private function warmUp(string $class): void
    {
        /** @var list<array{id: int, name: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.id AS id', 'e.name AS name')
            ->from($class, 'e')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['name']] = $row['id'];
        }

        $this->idsByClassAndName[$class] = $map;
    }

    /**
     * @param class-string $class
     */
    private function anyName(string $class): string
    {
        if (!isset($this->idsByClassAndName[$class])) {
            $this->warmUp($class);
        }

        $name = array_key_first($this->idsByClassAndName[$class]);
        if (!\is_string($name)) {
            throw new \RuntimeException(sprintf('No lookup rows found for %s.', $class));
        }

        return $name;
    }
}
