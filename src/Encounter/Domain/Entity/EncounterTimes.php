<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\AnchorSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Process timestamps of one ED visit (`ed_visit` times group), Europe/Berlin local time with minute
 * precision as exchanged. Durations are not stored; they are computed from these timestamps.
 *
 * At least one of ED admission and first assessment contact is set; together they give the time
 * anchor of the visit fingerprint (ADR 029).
 */
#[ORM\Embeddable]
class EncounterTimes
{
    public function __construct(
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $edAdmissionAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $firstAssessmentContactAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $assessmentStartAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $assessmentEndAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $physicianTargetAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $firstPhysicianAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $treatmentEndAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $edDischargeAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $adminAdmissionAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $adminDischargeAt = null,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $preInpatientAdmissionAt = null,
    ) {
    }

    /**
     * Anchor rule of `ed-visit:v1`: first assessment contact, otherwise ED admission.
     */
    public function anchorSource(): ?AnchorSource
    {
        return match (true) {
            $this->firstAssessmentContactAt instanceof \DateTimeImmutable => AnchorSource::Assessment,
            $this->edAdmissionAt instanceof \DateTimeImmutable => AnchorSource::Admission,
            default => null,
        };
    }

    public function anchorAt(): ?\DateTimeImmutable
    {
        return $this->firstAssessmentContactAt ?? $this->edAdmissionAt;
    }

    public function getEdAdmissionAt(): ?\DateTimeImmutable
    {
        return $this->edAdmissionAt;
    }

    public function getFirstAssessmentContactAt(): ?\DateTimeImmutable
    {
        return $this->firstAssessmentContactAt;
    }

    public function getAssessmentStartAt(): ?\DateTimeImmutable
    {
        return $this->assessmentStartAt;
    }

    public function getAssessmentEndAt(): ?\DateTimeImmutable
    {
        return $this->assessmentEndAt;
    }

    public function getPhysicianTargetAt(): ?\DateTimeImmutable
    {
        return $this->physicianTargetAt;
    }

    public function getFirstPhysicianAt(): ?\DateTimeImmutable
    {
        return $this->firstPhysicianAt;
    }

    public function getTreatmentEndAt(): ?\DateTimeImmutable
    {
        return $this->treatmentEndAt;
    }

    public function getEdDischargeAt(): ?\DateTimeImmutable
    {
        return $this->edDischargeAt;
    }

    public function getAdminAdmissionAt(): ?\DateTimeImmutable
    {
        return $this->adminAdmissionAt;
    }

    public function getAdminDischargeAt(): ?\DateTimeImmutable
    {
        return $this->adminDischargeAt;
    }

    public function getPreInpatientAdmissionAt(): ?\DateTimeImmutable
    {
        return $this->preInpatientAdmissionAt;
    }
}
