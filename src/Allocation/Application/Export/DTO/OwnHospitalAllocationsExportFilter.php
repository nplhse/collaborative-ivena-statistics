<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export\DTO;

final readonly class OwnHospitalAllocationsExportFilter
{
    /**
     * @param list<int>|null $hospitalIds
     */
    public function __construct(
        public \DateTimeInterface $dateFrom,
        public \DateTimeInterface $dateTo,
        public ?\DateTimeInterface $timeFrom = null,
        public ?\DateTimeInterface $timeTo = null,
        public ?array $hospitalIds = null,
        public ?string $urgency = null,
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
        public bool $includeIndicationRaw = false,
    ) {
    }

    public function toListFilterCriteria(): AllocationListFilterCriteria
    {
        return new AllocationListFilterCriteria(
            urgency: $this->urgency,
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

    /**
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'dateFrom' => $this->dateFrom->format('Y-m-d'),
            'dateTo' => $this->dateTo->format('Y-m-d'),
            'timeFrom' => $this->timeFrom?->format('H:i:s'),
            'timeTo' => $this->timeTo?->format('H:i:s'),
            'hospitalIds' => $this->hospitalIds,
            'urgency' => $this->urgency,
            'requiresResus' => $this->requiresResus,
            'requiresCathlab' => $this->requiresCathlab,
            'indication' => $this->indication,
            'secondaryTransport' => $this->secondaryTransport,
            'isVentilated' => $this->isVentilated,
            'isShock' => $this->isShock,
            'isCPR' => $this->isCPR,
            'isPregnant' => $this->isPregnant,
            'isWorkAccident' => $this->isWorkAccident,
            'isInfectious' => $this->isInfectious,
            'infection' => $this->infection,
            'department' => $this->department,
            'speciality' => $this->speciality,
            'assignment' => $this->assignment,
            'occasion' => $this->occasion,
            'departmentWasClosed' => $this->departmentWasClosed,
            'transportType' => $this->transportType,
            'includeIndicationRaw' => $this->includeIndicationRaw,
        ];
    }
}
