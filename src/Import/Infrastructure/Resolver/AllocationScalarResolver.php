<?php

namespace App\Import\Infrastructure\Resolver;

use App\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;

final class AllocationScalarResolver implements AllocationEntityResolverInterface
{
    #[\Override]
    public function warm(): void
    {
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        if (!\is_int($dto->age)) {
            throw new \LogicException('Age must be integer after validation');
        }

        $entity->setAge($dto->age);
    }
}
