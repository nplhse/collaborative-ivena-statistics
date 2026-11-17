<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Import;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Contract\Import\EncounterRecordRow;
use App\Encounter\Domain\Enum\CaseStatus;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;
use Cois\Plugin\EdVisit\Domain\Entity\EdVisit;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterDisposition;
use Cois\Plugin\EdVisit\Domain\Entity\EncounterTimes;
use Cois\Plugin\EdVisit\Domain\Entity\InitialVitals;
use Cois\Plugin\EdVisit\Domain\Entity\Retriage;
use Cois\Plugin\EdVisit\Domain\Entity\Triage;
use Cois\Plugin\EdVisit\Domain\Enum\AdmissionType;
use Cois\Plugin\EdVisit\Domain\Enum\ArrivalMode;
use Cois\Plugin\EdVisit\Domain\Enum\IsolationCode;
use Cois\Plugin\EdVisit\Domain\Enum\ReferralSource;

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
