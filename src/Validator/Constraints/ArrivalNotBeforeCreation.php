<?php

namespace App\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ArrivalNotBeforeCreation extends Constraint
{
    public string $createdAtField = 'createdAt';

    public string $arrivalAtField = 'arrivalAt';

    public string $format = 'd.m.Y H:i';

    public string $message = 'Arrival time must be on/after created time.';

    /**
     * @param array<string, mixed>|null $options
     * @param array<int, string>|null   $groups
     */
    public function __construct(
        ?array $options = null,
        ?array $groups = null,
        mixed $payload = null,
        ?string $createdAtField = null,
        ?string $arrivalAtField = null,
        ?string $format = null,
        ?string $message = null,
    ) {
        parent::__construct($options ?? [], $groups, $payload);
        $this->createdAtField = $createdAtField ?? $this->createdAtField;
        $this->arrivalAtField = $arrivalAtField ?? $this->arrivalAtField;
        $this->format = $format ?? $this->format;
        $this->message = $message ?? $this->message;
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
