<?php

namespace App\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Service\Import\Contracts\AllocationEntityResolverInterface;
use App\Service\Import\DTO\AllocationRowDTO;

final class AllocationFlagResolver implements AllocationEntityResolverInterface
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
        $entity->setRequiresResus($dto->requiresResus ?? false);
        $entity->setRequiresCathlab($dto->requiresCathlab ?? false);
        $entity->setIsCPR($dto->isCPR ?? false);
        $entity->setIsVentilated($dto->isVentilated ?? false);
        $entity->setIsShock($dto->isShock ?? false);
        $entity->setIsPregnant($dto->isPregnant ?? false);
        $entity->setIsWithPhysician($dto->isWithPhysician ?? false);
    }
}
