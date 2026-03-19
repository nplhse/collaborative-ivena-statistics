<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Resolver\Strategy;

use App\Import\Application\Exception\InvalidDateException;

final class DateParsingStrategy
{
    /**
     * @param object $entity must expose setCreatedAt() and setArrivalAt()
     * @param object $dto    must expose createdAt and arrivalAt
     */
    public function apply(object $entity, object $dto): void
    {
        if (!\is_string($dto->createdAt) || '' === $dto->createdAt) {
            throw InvalidDateException::forField('createdAt', $dto->createdAt);
        }

        if (!\is_string($dto->arrivalAt) || '' === $dto->arrivalAt) {
            throw InvalidDateException::forField('arrivalAt', $dto->arrivalAt);
        }

        try {
            $entity->setCreatedAt(new \DateTimeImmutable($dto->createdAt));
        } catch (\Throwable) {
            throw InvalidDateException::forField('createdAt', $dto->createdAt);
        }

        try {
            $entity->setArrivalAt(new \DateTimeImmutable($dto->arrivalAt));
        } catch (\Throwable) {
            throw InvalidDateException::forField('arrivalAt', $dto->arrivalAt);
        }
    }
}
