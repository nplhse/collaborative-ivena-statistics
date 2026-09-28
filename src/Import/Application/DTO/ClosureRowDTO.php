<?php

declare(strict_types=1);

namespace App\Import\Application\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class ClosureRowDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $hospitalShortName = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $speciality = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public ?string $department = null;

    #[Assert\NotBlank]
    public ?string $careLevelLabel = null;

    #[Assert\NotBlank]
    public ?string $reasonLabel = null;

    #[Assert\NotBlank]
    public ?string $facilityKindLabel = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y')]
    public ?string $startsOn = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'H:i:s')]
    public ?string $startsAtTime = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y')]
    public ?string $endsOn = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'H:i:s')]
    public ?string $endsAtTime = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $durationMinutes = null;

    #[Assert\Length(max: 255)]
    public ?string $closureUnit = null;

    #[Assert\Length(max: 32)]
    public ?string $sourceGroupId = null;

    #[Assert\Length(max: 2000)]
    public ?string $remark = null;

    #[Assert\Length(max: 2000)]
    public ?string $internalRemark = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y H:i:s')]
    public ?string $sourceRecordedAt = null;

    #[Assert\NotBlank]
    #[Assert\DateTime(format: 'd.m.Y H:i:s')]
    public ?string $sourceChangedAt = null;
}
