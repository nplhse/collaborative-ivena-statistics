<?php

namespace App\Import\Domain\Validation\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ArrivalNotBeforeCreationValidator extends ConstraintValidator
{
    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ArrivalNotBeforeCreation) {
            throw new UnexpectedTypeException($constraint, ArrivalNotBeforeCreation::class);
        }

        if (null === $value) {
            return;
        }

        $createdRaw = $value->{$constraint->createdAtField} ?? null;
        $arrivalRaw = $value->{$constraint->arrivalAtField} ?? null;

        if (!is_string($createdRaw) || '' === $createdRaw || !is_string($arrivalRaw) || '' === $arrivalRaw) {
            return;
        }

        $created = \DateTimeImmutable::createFromFormat($constraint->format, $createdRaw);
        $arrival = \DateTimeImmutable::createFromFormat($constraint->format, $arrivalRaw);

        if (!$created instanceof \DateTimeImmutable || !$arrival instanceof \DateTimeImmutable) {
            return;
        }

        if ($arrival < $created) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath($constraint->arrivalAtField)
                ->addViolation();
        }
    }
}
