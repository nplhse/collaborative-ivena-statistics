<?php

declare(strict_types=1);

namespace App\Statistics\Infrastructure\Entity;

use App\Statistics\Infrastructure\Repository\AllocationStatsProjectionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Read-only schema mirror for allocation_stats_projection.
 *
 * @psalm-suppress UnusedClass Pro-forma Doctrine mapping; rows written via DBAL only.
 * @psalm-suppress MissingConstructor Hydrated by Doctrine ORM.
 * @psalm-suppress ClassMustBeFinal Not final: Doctrine may generate proxies for entities.
 */
#[ORM\Entity(repositoryClass: AllocationStatsProjectionRepository::class, readOnly: true)]
#[ORM\Table(name: 'allocation_stats_projection')]
class AllocationStatsProjection
{
    #[ORM\Id]
    #[ORM\Column]
    private int $id;

    #[ORM\Column]
    private int $importId;

    #[ORM\Column]
    private int $hospitalId;

    #[ORM\Column]
    private int $stateId;

    #[ORM\Column]
    private int $dispatchAreaId;

    #[ORM\Column]
    private int $specialityId;

    #[ORM\Column]
    private int $departmentId;

    #[ORM\Column(nullable: true)]
    private ?int $occasionId = null;

    #[ORM\Column]
    private int $assignmentId;

    #[ORM\Column(nullable: true)]
    private ?int $infectionId = null;

    #[ORM\Column(nullable: true)]
    private ?int $indicationNormalizedId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $arrivalAt;

    #[ORM\Column(type: 'smallint')]
    private int $createdYear;

    #[ORM\Column(type: 'smallint')]
    private int $createdQuarter;

    #[ORM\Column(type: 'smallint')]
    private int $createdMonth;

    #[ORM\Column(type: 'smallint')]
    private int $createdWeek;

    #[ORM\Column(type: 'smallint')]
    private int $createdDay;

    #[ORM\Column(type: 'smallint')]
    private int $createdWeekday;

    #[ORM\Column(type: 'smallint')]
    private int $createdHour;

    #[ORM\Column]
    private int $transportTimeMinutes;

    #[ORM\Column(nullable: true)]
    private ?int $age = null;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $genderCode = null;

    #[ORM\Column(type: 'smallint')]
    private int $urgencyCode;

    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $transportTypeCode = null;

    #[ORM\Column(nullable: true)]
    private ?bool $requiresResus = null;

    #[ORM\Column(nullable: true)]
    private ?bool $requiresCathlab = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isCpr = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isVentilated = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isWithPhysician = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getImportId(): int
    {
        return $this->importId;
    }

    public function getHospitalId(): int
    {
        return $this->hospitalId;
    }

    public function getStateId(): int
    {
        return $this->stateId;
    }

    public function getDispatchAreaId(): int
    {
        return $this->dispatchAreaId;
    }

    public function getSpecialityId(): int
    {
        return $this->specialityId;
    }

    public function getDepartmentId(): int
    {
        return $this->departmentId;
    }

    public function getOccasionId(): ?int
    {
        return $this->occasionId;
    }

    public function getAssignmentId(): int
    {
        return $this->assignmentId;
    }

    public function getInfectionId(): ?int
    {
        return $this->infectionId;
    }

    public function getIndicationNormalizedId(): ?int
    {
        return $this->indicationNormalizedId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getArrivalAt(): \DateTimeImmutable
    {
        return $this->arrivalAt;
    }

    public function getCreatedYear(): int
    {
        return $this->createdYear;
    }

    public function getCreatedQuarter(): int
    {
        return $this->createdQuarter;
    }

    public function getCreatedMonth(): int
    {
        return $this->createdMonth;
    }

    public function getCreatedWeek(): int
    {
        return $this->createdWeek;
    }

    public function getCreatedDay(): int
    {
        return $this->createdDay;
    }

    public function getCreatedWeekday(): int
    {
        return $this->createdWeekday;
    }

    public function getCreatedHour(): int
    {
        return $this->createdHour;
    }

    public function getTransportTimeMinutes(): int
    {
        return $this->transportTimeMinutes;
    }

    public function getAge(): ?int
    {
        return $this->age;
    }

    public function getGenderCode(): ?int
    {
        return $this->genderCode;
    }

    public function getUrgencyCode(): int
    {
        return $this->urgencyCode;
    }

    public function getTransportTypeCode(): ?int
    {
        return $this->transportTypeCode;
    }

    public function isRequiresResus(): ?bool
    {
        return $this->requiresResus;
    }

    public function isRequiresCathlab(): ?bool
    {
        return $this->requiresCathlab;
    }

    public function isCpr(): ?bool
    {
        return $this->isCpr;
    }

    public function isVentilated(): ?bool
    {
        return $this->isVentilated;
    }

    public function isWithPhysician(): ?bool
    {
        return $this->isWithPhysician;
    }
}
