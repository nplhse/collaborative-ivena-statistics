<?php

namespace App\Validator\Constraints;

use App\Service\Import\DTO\AllocationRowDTO;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ArrivalNotBeforeCreationValidator extends ConstraintValidator
{
    /**
     * @param AllocationRowDTO|null $value
     */
    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof ArrivalNotBeforeCreation) {
            throw new UnexpectedTypeException($constraint, ArrivalNotBeforeCreation::class);
        }

        if (null === $value) {
            return;
        }

        $createdRaw = $value->{$constraint->createdAtField} ?? null;
        $arrivalRaw = $value->{$constraint->arrivalAtField} ?? null;

        if (!$createdRaw || !$arrivalRaw) {
            return;
        }

        $created = \DateTimeImmutable::createFromFormat($constraint->format, (string) $createdRaw) ?: null;
        $arrival = \DateTimeImmutable::createFromFormat($constraint->format, (string) $arrivalRaw) ?: null;

        if (!$created || !$arrival) {
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
