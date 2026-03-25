<?php

declare(strict_types=1);

namespace App\Import\Domain\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ArrivalNotBeforeCreation extends Constraint
{
    /**
     * @param array<int, string>|null $groups
     */
    public function __construct(
        public string $createdAtField = 'createdAt',
        public string $arrivalAtField = 'arrivalAt',
        public string $format = 'd.m.Y H:i',
        public string $message = 'Arrival time must be on/after created time.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }

    #[\Override]
    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }

    #[\Override]
    public function validatedBy(): string
    {
        return ArrivalNotBeforeCreationValidator::class;
    }
}
