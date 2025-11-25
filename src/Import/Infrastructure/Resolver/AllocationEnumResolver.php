<?php

namespace App\Import\Infrastructure\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Import\Application\Contracts\AllocationEntityResolverInterface;
use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Application\Exception\InvalidEnumException;

final class AllocationEnumResolver implements AllocationEntityResolverInterface
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
        $entity->setGender(
            AllocationGender::tryFrom((string) $dto->gender)
            ?? throw InvalidEnumException::forField('gender', $dto->gender)
        );

        if (null !== $dto->transportType) {
            $entity->setTransportType(
                AllocationTransportType::tryFrom($dto->transportType)
                ?? throw InvalidEnumException::forField('transportType', $dto->transportType)
            );
        }

        if (!\is_int($dto->urgency)) {
            throw new \LogicException('Urgency must be integer after validation');
        }

        $entity->setUrgency(
            AllocationUrgency::tryFrom($dto->urgency)
            ?? throw InvalidEnumException::forField('urgency', (string) $dto->urgency)
        );
    }
}
