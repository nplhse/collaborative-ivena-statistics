<?php

namespace App\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Service\Import\Contracts\AllocationEntityResolverInterface;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Exception\InvalidDateException;

final class AllocationDateResolver implements AllocationEntityResolverInterface
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
        if (!\is_string($dto->createdAt) || '' === $dto->createdAt) {
            throw InvalidDateException::forField('createdAt', $dto->createdAt);
        }

        try {
            $entity->setCreatedAt(new \DateTimeImmutable($dto->createdAt));
        } catch (\Throwable) {
            throw InvalidDateException::forField('createdAt', $dto->createdAt);
        }

        if (!\is_string($dto->arrivalAt) || '' === $dto->arrivalAt) {
            throw InvalidDateException::forField('arrivalAt', $dto->arrivalAt);
        }

        try {
            $entity->setArrivalAt(new \DateTimeImmutable($dto->arrivalAt));
        } catch (\Throwable) {
            throw InvalidDateException::forField('arrivalAt', $dto->arrivalAt);
        }
    }
}
