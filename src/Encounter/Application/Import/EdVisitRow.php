<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Import\EncounterRecordRow;
use App\Encounter\Domain\Entity\EdVisit;
use App\Encounter\Domain\Entity\EncounterDisposition;
use App\Encounter\Domain\Entity\EncounterTimes;
use App\Encounter\Domain\Entity\InitialVitals;
use App\Encounter\Domain\Entity\Retriage;
use App\Encounter\Domain\Entity\Triage;
use App\Encounter\Domain\Enum\AdmissionType;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\CaseStatus;
use App\Encounter\Domain\Enum\IsolationCode;
use App\Encounter\Domain\Enum\ReferralSource;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;

/**
 * A valid `ed_visit` row with typed values, ready to become an Encounter.
 */
final readonly class EdVisitRow implements EncounterRecordRow
{
    /**
     * @param list<IsolationCode> $isolation
     */
    public function __construct(
        public int $line,
        public string $visitFingerprint,
        public string $caseKey,
        public int $age,
        public Sex $sex,
        public EncounterTimes $times,
        public ?ArrivalMode $arrivalMode,
        public ?ReferralSource $referralSource,
        public ?AdmissionType $admissionType,
        public ?CaseStatus $caseStatus,
        public Triage $triage,
        public Retriage $retriage,
        public EncounterDisposition $disposition,
        public ?Department $admittingDepartment,
        public InitialVitals $vitals,
        public ?true $workAccident,
        public ?true $tetanusProtected,
        public array $isolation,
    ) {
    }

    /**
     * The time anchor of the visit (ADR 029); a mapped row always has one.
     */
    #[\Override]
    public function coverageAt(): ?\DateTimeImmutable
    {
        return $this->times->anchorAt();
    }

    public function toEdVisit(Hospital $hospital, Import $import, int $keyGeneration): EdVisit
    {
        return new EdVisit(
            hospital: $hospital,
            import: $import,
            keyGeneration: $keyGeneration,
            visitFingerprint: $this->visitFingerprint,
            caseKey: $this->caseKey,
            age: $this->age,
            sex: $this->sex,
            times: $this->times,
            arrivalMode: $this->arrivalMode,
            referralSource: $this->referralSource,
            admissionType: $this->admissionType,
            caseStatus: $this->caseStatus,
            triage: $this->triage,
            retriage: $this->retriage,
            disposition: $this->disposition,
            admittingDepartment: $this->admittingDepartment,
            vitals: $this->vitals,
            workAccident: $this->workAccident,
            tetanusProtected: $this->tetanusProtected,
            isolation: $this->isolation,
        );
    }
}
