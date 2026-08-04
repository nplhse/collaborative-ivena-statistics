<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form\Validation;

use App\Allocation\Application\Export\ExportDateTimeRangeResolver;
use App\Allocation\UI\Form\Model\OwnHospitalAllocationsExportFormData;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

final class ExportDateRangeValidValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ExportDateTimeRangeResolver $dateTimeRangeResolver,
    ) {
    }

    #[\Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ExportDateRangeValid) {
            throw new UnexpectedTypeException($constraint, ExportDateRangeValid::class);
        }

        if (null === $value) {
            return;
        }

        if (!$value instanceof OwnHospitalAllocationsExportFormData) {
            throw new UnexpectedValueException($value, OwnHospitalAllocationsExportFormData::class);
        }

        if (!$value->dateFrom instanceof \DateTimeInterface || !$value->dateTo instanceof \DateTimeInterface) {
            return;
        }

        try {
            $this->dateTimeRangeResolver->resolve(
                $value->dateFrom,
                $value->dateTo,
                $value->timeFrom,
                $value->timeTo,
            );
        } catch (\InvalidArgumentException) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath('dateTo')
                ->addViolation();
        }
    }
}
