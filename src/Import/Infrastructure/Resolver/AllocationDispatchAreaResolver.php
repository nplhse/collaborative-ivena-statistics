<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\DispatchAreaStateReferenceStrategy;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationDispatchAreaResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly DispatchAreaStateReferenceStrategy $strategy,
    ) {
    }

    #[\Override]
    public function warm(): void
    {
        $this->strategy->warm();
    }

    #[\Override]
    public function supports(Allocation $entity, AllocationRowDTO $dto): bool
    {
        return true;
    }

    #[\Override]
    public function apply(Allocation $entity, AllocationRowDTO $dto): void
    {
        $this->strategy->apply($entity, (string) $dto->dispatchArea);
    }
}
