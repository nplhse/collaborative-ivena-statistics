<?php

namespace App\Entity;

use App\Enum\AllocationGender;
use App\Enum\AllocationTransportType;
use App\Enum\AllocationUrgency;
use App\Repository\AllocationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AllocationRepository::class)]
class Allocation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $hospital = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?DispatchArea $dispatchArea = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?State $state = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Import $import = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $arrivalAt = null;

    #[ORM\Column(enumType: AllocationGender::class)]
    private ?AllocationGender $gender = null;

    #[ORM\Column]
    private ?int $age = null;

    #[ORM\Column]
    private ?bool $requiresResus = null;

    #[ORM\Column]
    private ?bool $requiresCathlab = null;

    #[ORM\Column]
    private ?bool $isCPR = null;

    #[ORM\Column]
    private ?bool $isVentilated = null;

    #[ORM\Column]
    private ?bool $isShock = null;

    #[ORM\Column]
    private ?bool $isPregnant = null;

    #[ORM\Column]
    private ?bool $isWithPhysician = null;

    #[ORM\Column(nullable: true, enumType: AllocationTransportType::class)]
    private ?AllocationTransportType $transportType = null;

    #[ORM\Column(enumType: AllocationUrgency::class)]
    private ?AllocationUrgency $urgency = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Speciality $speciality = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Department $department = null;

    #[ORM\Column]
    private ?bool $departmentWasClosed = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Occasion $occasion = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Assignment $assignment = null;

    #[ORM\ManyToOne]
    private ?Infection $infection = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function setHospital(?Hospital $hospital): static
    {
        $this->hospital = $hospital;

        return $this;
    }

    public function getDispatchArea(): ?DispatchArea
    {
        return $this->dispatchArea;
    }

    public function setDispatchArea(?DispatchArea $dispatchArea): static
    {
        $this->dispatchArea = $dispatchArea;

        return $this;
    }

    public function getState(): ?State
    {
        return $this->state;
    }

    public function setState(?State $state): static
    {
        $this->state = $state;

        return $this;
    }

    public function getImport(): ?Import
    {
        return $this->import;
    }

    public function setImport(?Import $import): static
    {
        $this->import = $import;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getArrivalAt(): ?\DateTimeImmutable
    {
        return $this->arrivalAt;
    }

    public function setArrivalAt(\DateTimeImmutable $arrivalAt): static
    {
        if ($this->createdAt && $arrivalAt < $this->createdAt) {
            throw new \InvalidArgumentException('ArrivalAt cannot be before createdAt');
        }

        $this->arrivalAt = $arrivalAt;

        return $this;
    }

    public function getGender(): ?AllocationGender
    {
        return $this->gender;
    }

    public function setGender(AllocationGender $gender): static
    {
        $this->gender = $gender;

        return $this;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function setAge(int $age): static
    {
        if ($age < 1 || $age > 99) {
            throw new \InvalidArgumentException('Age is out of range');
        }

        $this->age = $age;

        return $this;
    }

    public function isRequiresResus(): ?bool
    {
        return $this->requiresResus;
    }

    public function setRequiresResus(bool $requiresResus): static
    {
        $this->requiresResus = $requiresResus;

        return $this;
    }

    public function isRequiresCathlab(): ?bool
    {
        return $this->requiresCathlab;
    }

    public function setRequiresCathlab(bool $requiresCathlab): static
    {
        $this->requiresCathlab = $requiresCathlab;

        return $this;
    }

    public function isCPR(): ?bool
    {
        return $this->isCPR;
    }

    public function setIsCPR(bool $isCPR): static
    {
        $this->isCPR = $isCPR;

        return $this;
    }

    public function isVentilated(): ?bool
    {
        return $this->isVentilated;
    }

    public function setIsVentilated(bool $isVentilated): static
    {
        $this->isVentilated = $isVentilated;

        return $this;
    }

    public function isShock(): ?bool
    {
        return $this->isShock;
    }

    public function setIsShock(bool $isShock): static
    {
        $this->isShock = $isShock;

        return $this;
    }

    public function isPregnant(): ?bool
    {
        return $this->isPregnant;
    }

    public function setIsPregnant(bool $isPregnant): static
    {
        $this->isPregnant = $isPregnant;

        return $this;
    }

    public function isWithPhysician(): ?bool
    {
        return $this->isWithPhysician;
    }

    public function setIsWithPhysician(bool $isWithPhysician): static
    {
        $this->isWithPhysician = $isWithPhysician;

        return $this;
    }

    public function getTransportType(): ?AllocationTransportType
    {
        return $this->transportType;
    }

    public function setTransportType(?AllocationTransportType $transportType): static
    {
        $this->transportType = $transportType;

        return $this;
    }

    public function getUrgency(): ?AllocationUrgency
    {
        return $this->urgency;
    }

    public function setUrgency(AllocationUrgency $urgency): static
    {
        $this->urgency = $urgency;

        return $this;
    }

    public function getSpeciality(): ?Speciality
    {
        return $this->speciality;
    }

    public function setSpeciality(?Speciality $speciality): static
    {
        $this->speciality = $speciality;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function isDepartmentWasClosed(): ?bool
    {
        return $this->departmentWasClosed;
    }

    public function setDepartmentWasClosed(bool $departmentWasClosed): static
    {
        $this->departmentWasClosed = $departmentWasClosed;

        return $this;
    }

    public function getOccasion(): ?Occasion
    {
        return $this->occasion;
    }

    public function setOccasion(?Occasion $occasion): static
    {
        $this->occasion = $occasion;

        return $this;
    }

    public function getAssignment(): ?Assignment
    {
        return $this->assignment;
    }

    public function setAssignment(?Assignment $assignment): static
    {
        $this->assignment = $assignment;

        return $this;
    }

    public function getInfection(): ?Infection
    {
        return $this->infection;
    }

    public function setInfection(?Infection $infection): static
    {
        $this->infection = $infection;

        return $this;
    }
}
