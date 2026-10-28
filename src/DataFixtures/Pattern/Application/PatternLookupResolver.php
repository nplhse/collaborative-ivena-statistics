<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use Doctrine\ORM\EntityManagerInterface;

final class PatternLookupResolver
{
    /** @var array<class-string, array<string, int>> */
    private array $idsByClassAndName = [];

    /** @var array<string, array{normalized: int, raw: int|null}> PZC code → normalized indication and a raw indication mapped to it */
    private array $indicationIdsByCode = [];

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
        if ([] === $this->indicationIdsByCode) {
            // One query instead of two per sampled allocation; the lowest id wins if a code is there twice.
            /** @var list<array{code: int, id: int, raw_id: int|null}> $rows */
            $rows = $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT DISTINCT ON (n.code) n.code AS code, n.id AS id, (SELECT MIN(r.id) FROM indication_raw r WHERE r.normalized_id = n.id) AS raw_id
                 FROM indication_normalized n ORDER BY n.code, n.id',
            );
            foreach ($rows as $row) {
                $this->indicationIdsByCode[str_pad((string) $row['code'], 3, '0', \STR_PAD_LEFT)] = [
                    'normalized' => $row['id'],
                    'raw' => $row['raw_id'],
                ];
            }
        }

        $ids = $this->indicationIdsByCode[$code] ?? throw new \RuntimeException(sprintf('Unknown PZC code "%s" for %s.', $code, IndicationNormalized::class));

        /** @var IndicationNormalized $normalized */
        $normalized = $this->entityManager->getReference(IndicationNormalized::class, $ids['normalized']);
        if (null === $ids['raw']) {
            return [$normalized, $this->referenceAny(IndicationRaw::class)];
        }

        /** @var IndicationRaw $raw */
        $raw = $this->entityManager->getReference(IndicationRaw::class, $ids['raw']);

        return [$normalized, $raw];
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
