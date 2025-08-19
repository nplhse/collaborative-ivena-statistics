<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Import\DTO;

use App\Service\Import\DTO\AllocationRowDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AllocationRowDTOTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    private function makeValidDto(): AllocationRowDTO
    {
        $dto = new AllocationRowDTO();
        $dto->dispatchArea = 'Leitstelle Test';
        $dto->state = 'Hessen';
        $dto->hospital = 'Test Hospital';
        $dto->createdAt = '01.01.2025 10:00';
        $dto->arrivalAt = '01.01.2025 10:30';
        $dto->gender = 'M';
        $dto->age = 42;
        $dto->requiresResus = true;
        $dto->requiresCathlab = false;
        $dto->isCPR = false;
        $dto->isVentilated = false;
        $dto->isShock = false;
        $dto->isPregnant = false;
        $dto->isWithPhysician = true;
        $dto->transportType = 'Boden';

        return $dto;
    }

    public function testValidDtoProducesNoViolations(): void
    {
        $dto = $this->makeValidDto();

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testRequiredFieldsCauseViolations(): void
    {
        $dto = $this->makeValidDto();
        $dto->dispatchArea = '';
        $dto->state = '';
        $dto->hospital = '';
        $dto->createdAt = '';
        $dto->arrivalAt = '';

        $violations = $this->validator->validate($dto);

        self::assertGreaterThanOrEqual(5, count($violations));

        $props = array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($violations));

        self::assertContains('dispatchArea', $props);
        self::assertContains('state', $props);
        self::assertContains('hospital', $props);
        self::assertContains('createdAt', $props);
        self::assertContains('arrivalAt', $props);
    }

    public function testDateTimeFormatIsValidated(): void
    {
        $dto = $this->makeValidDto();
        $dto->createdAt = '2025-01-01 10:00';

        $violations = $this->validator->validate($dto);
        $hasCreatedFormatViolation = false;

        foreach ($violations as $v) {
            if ('createdAt' === $v->getPropertyPath()) {
                $hasCreatedFormatViolation = true;
                break;
            }
        }
        self::assertTrue($hasCreatedFormatViolation, (string) $violations);

        $dto->createdAt = '01.01.2025 10:00';

        $violations = $this->validator->validate($dto);

        self::assertCount(0, $violations, (string) $violations);
    }

    public function testArrivalBeforeCreatedYieldsClassLevelViolation(): void
    {
        $dto = $this->makeValidDto();
        $dto->createdAt = '01.01.2025 10:00';
        $dto->arrivalAt = '01.01.2025 09:59';

        $violations = $this->validator->validate($dto);
        $hasArrivalViolation = false;

        self::assertGreaterThanOrEqual(1, count($violations));

        foreach ($violations as $v) {
            if ('arrivalAt' === $v->getPropertyPath()) {
                $hasArrivalViolation = true;
                break;
            }
        }

        self::assertTrue($hasArrivalViolation, (string) $violations);
    }

    #[DataProvider('ageProvider')]
    public function testAgeValidation(?int $age, bool $isValid): void
    {
        $dto = $this->makeValidDto();
        $dto->age = $age;

        $violations = $this->validator->validate($dto);

        if ($isValid) {
            self::assertCount(0, $violations);
        } else {
            $hasAgeViolation = false;

            foreach ($violations as $v) {
                if ('age' === $v->getPropertyPath()) {
                    $hasAgeViolation = true;
                    break;
                }
            }

            self::assertTrue($hasAgeViolation);
        }
    }

    #[DataProvider('transportProvider')]
    public function testTransportChoice(?string $value, bool $isValid): void
    {
        $dto = $this->makeValidDto();
        $dto->transportType = $value;

        $violations = $this->validator->validate($dto);

        if ($isValid) {
            self::assertCount(0, $violations);
        } else {
            self::assertContains('transportType', array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($violations)), (string) $violations
            );
        }
    }

    public static function ageProvider(): iterable
    {
        yield 'null is not allowed' => [null, false];
        yield 'Zero is too small' => [0, false];
        yield 'Minimum is valid' => [1, true];
        yield 'In Between is valid' => [50, true];
        yield 'Maximum is valid' => [99, true];
        yield 'Too large is invalid' => [100, false];
    }

    public static function transportProvider(): iterable
    {
        yield 'null is allowed' => [null, true];
        yield 'Valid value' => ['Boden', true];
        yield 'Invalid value' => ['Flugtaxi', false];
    }
}
