<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Form\Validation;

use App\Allocation\Application\Export\ExportDateTimeRangeResolver;
use App\Allocation\UI\Form\Model\OwnHospitalAllocationsExportFormData;
use App\Allocation\UI\Form\Validation\ExportDateRangeValid;
use App\Allocation\UI\Form\Validation\ExportDateRangeValidValidator;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ExportDateRangeValidValidator>
 */
final class ExportDateRangeValidValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ExportDateRangeValidValidator
    {
        return new ExportDateRangeValidValidator(new ExportDateTimeRangeResolver());
    }

    public function testNoViolationForValidDateRange(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-31');

        $this->validator->validate($data, new ExportDateRangeValid());

        $this->assertNoViolation();
    }

    public function testNoViolationWhenDatesMissing(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-01');

        $this->validator->validate($data, new ExportDateRangeValid());

        $this->assertNoViolation();
    }

    public function testSkipsNullValue(): void
    {
        $this->validator->validate(null, new ExportDateRangeValid());

        $this->assertNoViolation();
    }

    public function testThrowsForInvalidConstraintType(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate(null, new NotBlank());
    }

    public function testThrowsForNonFormDataValue(): void
    {
        $this->expectException(UnexpectedValueException::class);

        $this->validator->validate(new \stdClass(), new ExportDateRangeValid());
    }

    public function testViolationWhenDatesReversed(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-02-01');
        $data->dateTo = new \DateTimeImmutable('2026-01-01');

        $constraint = new ExportDateRangeValid();

        $this->validator->validate($data, $constraint);

        $this
            ->buildViolation($constraint->message)
            ->atPath('property.path.dateTo')
            ->assertRaised();
    }

    public function testViolationWhenSameDayTimesReversed(): void
    {
        $data = new OwnHospitalAllocationsExportFormData();
        $data->dateFrom = new \DateTimeImmutable('2026-01-15');
        $data->dateTo = new \DateTimeImmutable('2026-01-15');
        $data->timeFrom = new \DateTimeImmutable('1970-01-01 18:00:00');
        $data->timeTo = new \DateTimeImmutable('1970-01-01 08:00:00');

        $constraint = new ExportDateRangeValid();

        $this->validator->validate($data, $constraint);

        $this
            ->buildViolation($constraint->message)
            ->atPath('property.path.dateTo')
            ->assertRaised();
    }
}
