<?php

namespace App\Import\Infrastructure\Resolver;

use App\Entity\Allocation;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\Exception\InvalidDateException;

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
