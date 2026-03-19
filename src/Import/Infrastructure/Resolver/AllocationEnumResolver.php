<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\EnumMappingStrategy;

final class AllocationEnumResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly EnumMappingStrategy $strategy,
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
        // Allocation: Gender/Urgency sind Pflichtfelder (optional=false).
        $this->strategy->apply($entity, $dto, genderOptional: false, urgencyOptional: false);
    }
}
