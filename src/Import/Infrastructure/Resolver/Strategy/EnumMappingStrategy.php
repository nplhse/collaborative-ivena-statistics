<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Import\Application\Exception\InvalidEnumException;

final class EnumMappingStrategy
{
    /**
     * @param object $entity must expose setGender(), setTransportType(), setUrgency()
     * @param object $dto    must expose gender, transportType, urgency
     */
    public function apply(object $entity, object $dto, bool $genderOptional, bool $urgencyOptional): void
    {
        $gender = $dto->gender;
        if (null !== $gender) {
            $genderEnum = AllocationGender::tryFrom((string) $gender);
            if (null === $genderEnum) {
                throw InvalidEnumException::forField('gender', $gender);
            }
            $entity->setGender($genderEnum);
        } elseif (!$genderOptional) {
            throw InvalidEnumException::forField('gender', $gender);
        }

        $transportType = $dto->transportType;
        if (null !== $transportType) {
            $transportEnum = AllocationTransportType::tryFrom((string) $transportType);
            if (null === $transportEnum) {
                throw InvalidEnumException::forField('transportType', $transportType);
            }
            $entity->setTransportType($transportEnum);
        }

        $urgency = $dto->urgency;
        if (null === $urgency) {
            if ($urgencyOptional) {
                return;
            }
            throw new \LogicException('Urgency must be integer after validation');
        }

        if (!\is_int($urgency)) {
            throw new \LogicException($urgencyOptional ? 'urgency must be integer after validation' : 'Urgency must be integer after validation');
        }

        $urgencyEnum = AllocationUrgency::tryFrom($urgency);
        if (null === $urgencyEnum) {
            throw InvalidEnumException::forField('urgency', (string) $urgency);
        }

        $entity->setUrgency($urgencyEnum);
    }
}
