<?php

namespace App\Service\Import\Contracts;

use App\Entity\Allocation;
use App\Service\Import\DTO\AllocationRowDTO;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('allocation.import_resolver')]
interface AllocationEntityResolverInterface
{
    public function warm(): void;

    public function supports(Allocation $entity, AllocationRowDTO $dto): bool;

    public function apply(Allocation $entity, AllocationRowDTO $dto): void;
}
