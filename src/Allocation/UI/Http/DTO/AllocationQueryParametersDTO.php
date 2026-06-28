<?php

declare(strict_types=1);

namespace App\Allocation\UI\Http\DTO;

use App\Allocation\Application\Export\DTO\AllocationListFilterCriteria;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class AllocationQueryParametersDTO
{
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 50,

        #[Assert\Length(max: 2048)]
        public ?string $cursor = null,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['age', 'arrivalAt'])]
        public string $sortBy = 'arrivalAt',

        #[Assert\GreaterThan(0)]
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
    ) {
    }

    public function toListFilterCriteria(): AllocationListFilterCriteria
    {
        return new AllocationListFilterCriteria(
            importId: $this->importId,
            tier: $this->tier,
            location: $this->location,
            size: $this->size,
            urgency: $this->urgency,
            dispatchArea: $this->dispatchArea,
            state: $this->state,
            requiresResus: $this->requiresResus,
            requiresCathlab: $this->requiresCathlab,
            indication: $this->indication,
            secondaryTransport: $this->secondaryTransport,
            isVentilated: $this->isVentilated,
            isShock: $this->isShock,
            isCPR: $this->isCPR,
            isPregnant: $this->isPregnant,
            isWorkAccident: $this->isWorkAccident,
            isInfectious: $this->isInfectious,
            infection: $this->infection,
            department: $this->department,
            speciality: $this->speciality,
            assignment: $this->assignment,
            occasion: $this->occasion,
            departmentWasClosed: $this->departmentWasClosed,
            transportType: $this->transportType,
        );
    }
}
