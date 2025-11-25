<?php

namespace App\Import\Application\DTO;

use App\Import\Domain\Validation\Constraints\ArrivalNotBeforeCreation;
use Symfony\Component\Validator\Constraints as Assert;

#[ArrivalNotBeforeCreation(
    createdAtField: 'createdAt',
    arrivalAtField: 'arrivalAt',
    format: 'd.m.Y H:i',
    message: 'arrivalAt cannot be before createdAt'
)]
final class AllocationRowDTO
{
    #[Assert\NotBlank]
    public ?string $dispatchArea = null;

    #[Assert\NotBlank]
    public ?string $hospital = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y H:i')]
    public ?string $createdAt = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y H:i')]
    public ?string $arrivalAt = null;

    #[Assert\NotNull]
    #[Assert\Choice(choices: ['M', 'F', 'X'])]
    public ?string $gender = null;

    #[Assert\NotNull]
    #[Assert\Type('integer')]
    #[Assert\Range(min: 1, max: 99)]
    public ?int $age = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $requiresResus = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $requiresCathlab = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $isCPR = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $isVentilated = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $isShock = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $isPregnant = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $isWithPhysician = null;

    #[Assert\Choice(choices: ['G', 'A'], message: 'Unknown transport')]
    public ?string $transportType = null;

    #[Assert\NotNull]
    #[Assert\Type('integer')]
    #[Assert\Range(min: 1, max: 3)]
    public ?int $urgency = null;

    #[Assert\NotBlank]
    public ?string $speciality = null;

    #[Assert\NotBlank]
    public ?string $department = null;

    #[Assert\NotNull]
    #[Assert\Type('bool')]
    public ?bool $departmentWasClosed = null;

    #[Assert\NotBlank]
    public ?string $assignment = null;

    public ?string $occasion = null;

    public ?string $infection = null;

    #[Assert\NotNull]
    #[Assert\Type('integer')]
    #[Assert\Range(min: 100, max: 999)]
    public ?int $indicationCode = null;

    #[Assert\NotBlank]
    public ?string $indication = null;
}
