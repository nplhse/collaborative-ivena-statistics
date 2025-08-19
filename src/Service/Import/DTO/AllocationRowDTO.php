<?php

namespace App\Service\Import\DTO;

use App\Validator\Constraints\ArrivalNotBeforeCreation;
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
    public ?string $state = null;

    #[Assert\NotBlank]
    public ?string $hospital = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y H:i')]
    public ?string $createdAt = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y H:i')]
    public ?string $arrivalAt = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['M', 'W', 'D', 'X'])]
    public ?string $gender = null;

    #[Assert\NotNull]
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

    #[Assert\Choice(choices: ['Boden', 'Luft'], message: 'Unknown transport')]
    public ?string $transportType = null;
}
