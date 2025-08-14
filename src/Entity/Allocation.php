<?php

namespace App\Entity;

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

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $arrivalAt = null;

    #[ORM\Column]
    private ?bool $requiresResus = null;

    #[ORM\Column]
    private ?bool $requiresCathlab = null;

    #[ORM\Column(length: 1)]
    private ?string $gender = null;

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
        $this->arrivalAt = $arrivalAt;

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

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function setGender(string $gender): static
    {
        $this->gender = $gender;

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
}
