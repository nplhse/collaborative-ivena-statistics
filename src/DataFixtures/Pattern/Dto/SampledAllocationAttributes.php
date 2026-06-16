<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Dto;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Entity\Infection;
use App\Allocation\Domain\Entity\Occasion;
use App\Allocation\Domain\Entity\SecondaryTransport;
use App\Allocation\Domain\Entity\Speciality;
use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;

final readonly class SampledAllocationAttributes
{
    public function __construct(
        public \DateTimeImmutable $createdAt,
        public \DateTimeImmutable $arrivalAt,
        public AllocationGender $gender,
        public int $age,
        public AllocationUrgency $urgency,
        public ?AllocationTransportType $transportType,
        public Speciality $speciality,
        public Department $department,
        public Assignment $assignment,
        public Occasion $occasion,
        public IndicationRaw $indicationRaw,
        public ?IndicationNormalized $indicationNormalized,
        public ?Infection $infection,
        public ?SecondaryTransport $secondaryTransport,
        public bool $requiresResus,
        public bool $requiresCathlab,
        public bool $isCpr,
        public bool $isVentilated,
        public bool $isShock,
        public bool $isPregnant,
        public bool $isWorkAccident,
        public bool $isWithPhysician,
        public bool $departmentWasClosed,
    ) {
    }
}
