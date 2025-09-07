<?php

namespace App\Service\Import\Resolver;

use App\Entity\Allocation;
use App\Enum\AllocationGender;
use App\Enum\AllocationTransportType;
use App\Enum\AllocationUrgency;
use App\Service\Import\Contracts\AllocationEntityResolverInterface;
use App\Service\Import\DTO\AllocationRowDTO;
use App\Service\Import\Exception\InvalidEnumException;

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
