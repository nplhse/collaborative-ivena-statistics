<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Infrastructure\Resolver\Strategy\SpecialityDepartmentReferenceStrategy;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem('allocation.import_resolver')]
final class AllocationSpecialityResolver implements AllocationEntityResolverInterface
{
    public function __construct(
        private readonly SpecialityDepartmentReferenceStrategy $strategy,
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
        // Allocation: dto-null => Entity wird false (departmentWasClosed ist non-nullbar).
        $this->strategy->apply(
            $entity,
            $dto->speciality,
            $dto->department,
            $dto->departmentWasClosed,
            static fn (?bool $v): bool => $v ?? false,
        );
    }
}
