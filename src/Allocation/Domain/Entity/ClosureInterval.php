<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Entity;

use App\Allocation\Domain\Enum\ClosureCareLevel;
use App\Allocation\Domain\Enum\ClosureFacilityKind;
use App\Allocation\Domain\Enum\ClosureReason;
use App\Allocation\Domain\Service\ClosureIntervalDuration;
use App\Allocation\Infrastructure\Repository\ClosureIntervalRepository;
use App\Import\Domain\Entity\Import;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One imported closure row: one department and one care level over a time span.
 *
 * closureUnit is the hospital's own group label at import time. sourceGroupId is
 * the IVENA action id. Neither is a shared taxonomy.
 */
#[Audit\Audited]
#[ORM\Entity(repositoryClass: ClosureIntervalRepository::class)]
#[ORM\Table(name: 'closure_interval')]
#[ORM\Index(name: 'idx_closure_interval_hospital_unit', columns: ['hospital_id', 'closure_unit'])]
#[ORM\Index(name: 'idx_closure_interval_source_group', columns: ['source_group_id'])]
#[ORM\Index(name: 'idx_closure_interval_import', columns: ['import_id'])]
class ClosureInterval
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
    private ?Import $import = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Speciality $speciality = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Department $department = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $endsAt = null;

    #[ORM\Column(length: 32, enumType: ClosureCareLevel::class)]
    private ?ClosureCareLevel $careLevel = null;

    #[ORM\Column(length: 64, enumType: ClosureReason::class)]
    private ?ClosureReason $reason = null;

    #[ORM\Column(length: 32, enumType: ClosureFacilityKind::class)]
    private ?ClosureFacilityKind $facilityKind = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $closureUnit = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $sourceGroupId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $remark = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $internalRemark = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $sourceRecordedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $sourceChangedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function setHospital(Hospital $hospital): static
    {
        $this->hospital = $hospital;

        return $this;
    }

    public function getImport(): ?Import
    {
        return $this->import;
    }

    public function setImport(Import $import): static
    {
        $this->import = $import;

        return $this;
    }

    public function getSpeciality(): ?Speciality
    {
        return $this->speciality;
    }

    public function setSpeciality(Speciality $speciality): static
    {
        $this->speciality = $speciality;

        return $this;
    }

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(Department $department): static
    {
        $this->department = $department;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function durationInMinutes(): int
    {
        if (!$this->startsAt instanceof \DateTimeImmutable || !$this->endsAt instanceof \DateTimeImmutable) {
            throw new \LogicException('Closure interval has no start or end.');
        }

        return ClosureIntervalDuration::minutesBetween($this->startsAt, $this->endsAt);
    }

    public function getCareLevel(): ?ClosureCareLevel
    {
        return $this->careLevel;
    }

    public function setCareLevel(ClosureCareLevel $careLevel): static
    {
        $this->careLevel = $careLevel;

        return $this;
    }

    public function getReason(): ?ClosureReason
    {
        return $this->reason;
    }

    public function setReason(ClosureReason $reason): static
    {
        $this->reason = $reason;

        return $this;
    }

    public function getFacilityKind(): ?ClosureFacilityKind
    {
        return $this->facilityKind;
    }

    public function setFacilityKind(ClosureFacilityKind $facilityKind): static
    {
        $this->facilityKind = $facilityKind;

        return $this;
    }

    public function getClosureUnit(): ?string
    {
        return $this->closureUnit;
    }

    public function setClosureUnit(?string $closureUnit): static
    {
        $this->closureUnit = $closureUnit;

        return $this;
    }

    public function getSourceGroupId(): ?string
    {
        return $this->sourceGroupId;
    }

    public function setSourceGroupId(?string $sourceGroupId): static
    {
        $this->sourceGroupId = $sourceGroupId;

        return $this;
    }

    public function getRemark(): ?string
    {
        return $this->remark;
    }

    public function setRemark(?string $remark): static
    {
        $this->remark = $remark;

        return $this;
    }

    public function getInternalRemark(): ?string
    {
        return $this->internalRemark;
    }

    public function setInternalRemark(?string $internalRemark): static
    {
        $this->internalRemark = $internalRemark;

        return $this;
    }

    public function getSourceRecordedAt(): ?\DateTimeImmutable
    {
        return $this->sourceRecordedAt;
    }

    public function setSourceRecordedAt(\DateTimeImmutable $sourceRecordedAt): static
    {
        $this->sourceRecordedAt = $sourceRecordedAt;

        return $this;
    }

    public function getSourceChangedAt(): ?\DateTimeImmutable
    {
        return $this->sourceChangedAt;
    }

    public function setSourceChangedAt(\DateTimeImmutable $sourceChangedAt): static
    {
        $this->sourceChangedAt = $sourceChangedAt;

        return $this;
    }
}
