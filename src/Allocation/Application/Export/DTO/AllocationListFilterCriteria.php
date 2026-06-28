<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export\DTO;

final readonly class AllocationListFilterCriteria
{
    public function __construct(
        public ?int $importId = null,
        public ?string $tier = null,
        public ?string $location = null,
        public ?string $size = null,
        public ?string $urgency = null,
        public ?int $dispatchArea = null,
        public ?int $state = null,
        public ?int $requiresResus = null,
        public ?int $requiresCathlab = null,
        public ?int $indication = null,
        public ?int $secondaryTransport = null,
        public ?int $isVentilated = null,
        public ?int $isShock = null,
        public ?int $isCPR = null,
        public ?int $isPregnant = null,
        public ?int $isWorkAccident = null,
        public ?int $isInfectious = null,
        public ?int $infection = null,
        public ?int $department = null,
        public ?int $speciality = null,
        public ?int $assignment = null,
        public ?int $occasion = null,
        public ?int $departmentWasClosed = null,
        public ?string $transportType = null,
        /** @var list<int>|null */
        public ?array $hospitalIds = null,
    ) {
    }
}
