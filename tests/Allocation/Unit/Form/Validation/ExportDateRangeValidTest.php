<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Form\Validation;

use App\Allocation\UI\Form\Validation\ExportDateRangeValid;
use App\Allocation\UI\Form\Validation\ExportDateRangeValidValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraint;

final class ExportDateRangeValidTest extends TestCase
{
    public function testIsClassConstraintValidatedByExportDateRangeValidValidator(): void
    {
        $constraint = new ExportDateRangeValid();

        self::assertSame(Constraint::CLASS_CONSTRAINT, $constraint->getTargets());
        self::assertSame(ExportDateRangeValidValidator::class, $constraint->validatedBy());
    }
}
