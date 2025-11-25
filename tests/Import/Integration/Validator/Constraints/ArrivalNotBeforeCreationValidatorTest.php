<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Validator\Constraints;

use App\Import\Application\DTO\AllocationRowDTO;
use App\Import\Domain\Validation\Constraints\ArrivalNotBeforeCreation;
use App\Import\Domain\Validation\Constraints\ArrivalNotBeforeCreationValidator;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<ArrivalNotBeforeCreationValidator>
 */
final class ArrivalNotBeforeCreationValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ArrivalNotBeforeCreationValidator
    {
        return new ArrivalNotBeforeCreationValidator();
    }

    public function testNoViolationWhenArrivalEqualsCreated(): void
    {
        $dto = new AllocationRowDTO();
        $dto->createdAt = '01.01.2025 10:00';
        $dto->arrivalAt = '01.01.2025 10:00';

        $constraint = new ArrivalNotBeforeCreation();

        $this->validator->validate($dto, $constraint);

        $this->assertNoViolation();
    }

    public function testNoViolationWhenArrivalAfterCreated(): void
    {
        $dto = new AllocationRowDTO();
        $dto->createdAt = '01.01.2025 10:00';
        $dto->arrivalAt = '01.01.2025 10:01';

        $constraint = new ArrivalNotBeforeCreation();

        $this->validator->validate($dto, $constraint);

        $this->assertNoViolation();
    }

    public function testViolationWhenArrivalBeforeCreated(): void
    {
        $dto = new AllocationRowDTO();
        $dto->createdAt = '01.01.2025 10:00';
        $dto->arrivalAt = '01.01.2025 09:59';

        $constraint = new ArrivalNotBeforeCreation();

        $this->validator->validate($dto, $constraint);

        $this
            ->buildViolation($constraint->message)
            ->atPath('property.path.arrivalAt')
            ->assertRaised();
    }
}
