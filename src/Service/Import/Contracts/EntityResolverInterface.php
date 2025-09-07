<?php

namespace App\Service\Import\Contracts;

use App\Entity\Import;

/**
 * @template TEntity of object
 * @template TDto of object
 */
interface EntityResolverInterface
{
    public function warm(): void;

    /**
     * @param TDto $dto
     */
    public function supports(object $dto): bool;

    /**
     * @param TEntity $entity
     * @param TDto    $dto
     *
     * @throws \App\Service\Import\Exception\ImportException
     */
    public function apply(object $entity, object $dto, Import $import): void;
}
