<?php

namespace App\Import\Domain\Validation\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ArrivalNotBeforeCreation extends Constraint
{
    public string $createdAtField;

    public string $arrivalAtField;

    public string $format;

    public string $message;

    /**
     * @param array<int, string>|null $groups
     */
    public function __construct(
        string $createdAtField = 'createdAt',
        string $arrivalAtField = 'arrivalAt',
        string $format = 'd.m.Y H:i',
        string $message = 'Arrival time must be on/after created time.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        $this->createdAtField = $createdAtField;
        $this->arrivalAtField = $arrivalAtField;
        $this->format = $format;
        $this->message = $message;
        
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
