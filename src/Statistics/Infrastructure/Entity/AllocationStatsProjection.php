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
 *
 * The partial index conditions are written in the form PostgreSQL stores them (pg_get_expr), so the schema diff
 * stays empty (#1398).
 */
#[ORM\Entity(repositoryClass: AllocationStatsProjectionRepository::class, readOnly: true)]
#[ORM\Table(name: 'allocation_stats_projection')]
#[ORM\Index(name: 'idx_asp_age', columns: ['age'])]
#[ORM\Index(name: 'idx_asp_arrival_at', columns: ['arrival_at'])]
#[ORM\Index(name: 'idx_asp_assignment_id', columns: ['assignment_id'])]
#[ORM\Index(name: 'idx_asp_closed_department_hospital_created', columns: ['hospital_id', 'created_at'], options: ['where' => '(department_was_closed IS TRUE)'])]
#[ORM\Index(name: 'idx_asp_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_asp_created_day', columns: ['created_day'])]
#[ORM\Index(name: 'idx_asp_created_hour', columns: ['created_hour'])]
#[ORM\Index(name: 'idx_asp_created_month', columns: ['created_month'])]
#[ORM\Index(name: 'idx_asp_created_quarter', columns: ['created_quarter'])]
#[ORM\Index(name: 'idx_asp_created_week', columns: ['created_week'])]
#[ORM\Index(name: 'idx_asp_created_weekday', columns: ['created_weekday'])]
#[ORM\Index(name: 'idx_asp_created_year', columns: ['created_year'])]
#[ORM\Index(name: 'idx_asp_day_time_bucket', columns: ['day_time_bucket_code'])]
#[ORM\Index(name: 'idx_asp_department_id', columns: ['department_id'])]
#[ORM\Index(name: 'idx_asp_dispatch_area_hospital', columns: ['dispatch_area_id', 'hospital_id'])]
#[ORM\Index(name: 'idx_asp_dispatch_area_id', columns: ['dispatch_area_id'])]
#[ORM\Index(name: 'idx_asp_gender_code', columns: ['gender_code'])]
#[ORM\Index(name: 'idx_asp_hospital_created', columns: ['hospital_id', 'created_at'])]
#[ORM\Index(name: 'idx_asp_hospital_id', columns: ['hospital_id'])]
#[ORM\Index(name: 'idx_asp_hospital_indication', columns: ['hospital_id', 'indication_normalized_id'])]
#[ORM\Index(name: 'idx_asp_hospital_location_code', columns: ['hospital_location_code'])]
#[ORM\Index(name: 'idx_asp_hospital_tier_code', columns: ['hospital_tier_code'])]
#[ORM\Index(name: 'idx_asp_import_id', columns: ['import_id'])]
#[ORM\Index(name: 'idx_asp_indication_created', columns: ['indication_normalized_id', 'created_at'])]
#[ORM\Index(name: 'idx_asp_indication_hospital_created', columns: ['indication_normalized_id', 'hospital_id', 'created_at'])]
#[ORM\Index(name: 'idx_asp_indication_normalized_id', columns: ['indication_normalized_id'])]
#[ORM\Index(name: 'idx_asp_indication_weekday_daytime', columns: ['indication_normalized_id', 'created_weekday', 'day_time_bucket_code'])]
#[ORM\Index(name: 'idx_asp_indication_weekday_shift', columns: ['indication_normalized_id', 'created_weekday', 'shift_bucket_code'])]
#[ORM\Index(name: 'idx_asp_infection_id', columns: ['infection_id'])]
#[ORM\Index(name: 'idx_asp_is_pregnant', columns: ['is_pregnant'])]
#[ORM\Index(name: 'idx_asp_is_shock', columns: ['is_shock'])]
#[ORM\Index(name: 'idx_asp_is_with_physician', columns: ['is_with_physician'])]
#[ORM\Index(name: 'idx_asp_is_work_accident', columns: ['is_work_accident'])]
#[ORM\Index(name: 'idx_asp_location_tier_hospital', columns: ['hospital_location_code', 'hospital_tier_code', 'hospital_id'])]
#[ORM\Index(name: 'idx_asp_occasion_id', columns: ['occasion_id'])]
#[ORM\Index(name: 'idx_asp_requires_cathlab', columns: ['requires_cathlab'])]
#[ORM\Index(name: 'idx_asp_requires_resus', columns: ['requires_resus'])]
#[ORM\Index(name: 'idx_asp_secondary_indication_normalized_id', columns: ['secondary_indication_normalized_id'])]
#[ORM\Index(name: 'idx_asp_shift_bucket', columns: ['shift_bucket_code'])]
#[ORM\Index(name: 'idx_asp_speciality_id', columns: ['speciality_id'])]
#[ORM\Index(name: 'idx_asp_state_hospital', columns: ['state_id', 'hospital_id'])]
#[ORM\Index(name: 'idx_asp_state_id', columns: ['state_id'])]
#[ORM\Index(name: 'idx_asp_transport_type_code', columns: ['transport_type_code'])]
#[ORM\Index(name: 'idx_asp_urgency_code', columns: ['urgency_code'])]
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

    #[ORM\Column(nullable: true)]
    private ?int $secondaryIndicationNormalizedId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $arrivalAt;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdYear;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdQuarter;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdMonth;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdWeek;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdDay;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdWeekday;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $createdHour;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $dayTimeBucketCode;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $shiftBucketCode;

    #[ORM\Column]
    private int $transportTimeMinutes;

    #[ORM\Column(nullable: true)]
    private ?int $age = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT, nullable: true)]
    private ?int $genderCode = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT)]
    private int $urgencyCode;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT, nullable: true)]
    private ?int $transportTypeCode = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT, nullable: true)]
    private ?int $hospitalTierCode = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::SMALLINT, nullable: true)]
    private ?int $hospitalLocationCode = null;

    #[ORM\Column(nullable: true)]
    private ?bool $requiresResus = null;

    #[ORM\Column(nullable: true)]
    private ?bool $requiresCathlab = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isCpr = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isVentilated = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isShock = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isPregnant = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isWorkAccident = null;

    #[ORM\Column(nullable: true)]
    private ?bool $isWithPhysician = null;

    #[ORM\Column(nullable: true)]
    private ?int $secondaryTransportId = null;

    #[ORM\Column(nullable: true)]
    private ?bool $departmentWasClosed = null;

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

    public function getSecondaryIndicationNormalizedId(): ?int
    {
        return $this->secondaryIndicationNormalizedId;
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

    public function getDayTimeBucketCode(): int
    {
        return $this->dayTimeBucketCode;
    }

    public function getShiftBucketCode(): int
    {
        return $this->shiftBucketCode;
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

    public function getHospitalTierCode(): ?int
    {
        return $this->hospitalTierCode;
    }

    public function getHospitalLocationCode(): ?int
    {
        return $this->hospitalLocationCode;
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

    public function isShock(): ?bool
    {
        return $this->isShock;
    }

    public function isPregnant(): ?bool
    {
        return $this->isPregnant;
    }

    public function isWorkAccident(): ?bool
    {
        return $this->isWorkAccident;
    }

    public function isWithPhysician(): ?bool
    {
        return $this->isWithPhysician;
    }

    public function getSecondaryTransportId(): ?int
    {
        return $this->secondaryTransportId;
    }

    public function isDepartmentWasClosed(): ?bool
    {
        return $this->departmentWasClosed;
    }
}
