<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\FlagMappingStrategy;

final class AllocationFlagResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly FlagMappingStrategy $strategy,
    ) {
    }

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
        // Allocation: optional-DTO-Werte werden für die Entity nicht-nullbar gemacht.
        $this->strategy->apply($entity, $dto, true);
    }
}
