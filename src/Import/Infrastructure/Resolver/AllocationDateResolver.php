<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\DateParsingStrategy;

final class AllocationDateResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly DateParsingStrategy $strategy,
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
        $this->strategy->apply($entity, $dto);
    }
}
