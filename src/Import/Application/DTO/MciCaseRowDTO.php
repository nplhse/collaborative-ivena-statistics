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
final class MciCaseRowDTO
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

    #[Assert\NotBlank]
    public ?string $mciId = null;

    #[Assert\NotBlank]
    public ?string $mciTitle = null;

    #[Assert\Choice(choices: ['M', 'F', 'X'])]
    public ?string $gender = null;

    #[Assert\Type('integer')]
    #[Assert\Range(min: 1, max: 99)]
    public ?int $age = null;

    #[Assert\Type('bool')]
    public ?bool $requiresResus = null;

    #[Assert\Type('bool')]
    public ?bool $requiresCathlab = null;

    #[Assert\Type('bool')]
    public ?bool $isCPR = null;

    #[Assert\Type('bool')]
    public ?bool $isVentilated = null;

    #[Assert\Type('bool')]
    public ?bool $isShock = null;

    #[Assert\Type('bool')]
    public ?bool $isPregnant = null;

    #[Assert\Type('bool')]
    public ?bool $isWithPhysician = null;

    #[Assert\Choice(choices: ['G', 'A'], message: 'Unknown transport')]
    public ?string $transportType = null;

    #[Assert\Type('integer')]
    #[Assert\Range(min: 1, max: 3)]
    public ?int $urgency = null;

    public ?string $speciality = null;

    public ?string $department = null;

    #[Assert\Type('bool')]
    public ?bool $departmentWasClosed = null;

    public ?string $occasion = null;

    public ?string $infection = null;

    #[Assert\Type('integer')]
    #[Assert\Range(min: 100, max: 999)]
    public ?int $indicationCode = null;

    public ?string $indication = null;
}
