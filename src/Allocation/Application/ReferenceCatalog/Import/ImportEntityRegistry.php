<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Import;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogType;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\State;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finds catalog entities by public id: rows created in this import first, then the database.
 *
 * @internal used by ReferenceCatalogImportApplier only
 */
final class ImportEntityRegistry
{
    /** @var array<string, array<string, object>> type → lowercase id → entity */
    private array $entities = [];

    /** @var array<string, true> */
    private array $loaded = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function register(ReferenceCatalogType $type, string $id, object $entity): void
    {
        $this->entities[$type->value][strtolower($id)] = $entity;
    }

    public function get(ReferenceCatalogType $type, string $id): object
    {
        $key = strtolower($id);
        if (!isset($this->entities[$type->value][$key]) && !isset($this->loaded[$type->value])) {
            $this->loaded[$type->value] = true;
            foreach ($this->entityManager->getRepository($type->entityClass())->findAll() as $entity) {
                $this->entities[$type->value][strtolower($entity->getPublicIdString())] ??= $entity;
            }
        }

        return $this->entities[$type->value][$key]
            ?? throw new \LogicException(sprintf('The %s "%s" is neither in the package nor in the database.', $type->value, $id));
    }

    public function state(string $id): State
    {
        $entity = $this->get(ReferenceCatalogType::State, $id);
        if (!$entity instanceof State) {
            throw new \LogicException(sprintf('Expected a State for id "%s".', $id));
        }

        return $entity;
    }

    public function dispatchArea(string $id): DispatchArea
    {
        $entity = $this->get(ReferenceCatalogType::DispatchArea, $id);
        if (!$entity instanceof DispatchArea) {
            throw new \LogicException(sprintf('Expected a DispatchArea for id "%s".', $id));
        }

        return $entity;
    }

    public function normalizedIndication(string $id): IndicationNormalized
    {
        $entity = $this->get(ReferenceCatalogType::IndicationNormalized, $id);
        if (!$entity instanceof IndicationNormalized) {
            throw new \LogicException(sprintf('Expected a IndicationNormalized for id "%s".', $id));
        }

        return $entity;
    }
}
