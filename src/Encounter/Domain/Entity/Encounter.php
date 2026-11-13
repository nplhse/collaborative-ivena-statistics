<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Department;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\AdmissionType;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\ArrivalMode;
use App\Encounter\Domain\Enum\ArrivalRoute;
use App\Encounter\Domain\Enum\CaseStatus;
use App\Encounter\Domain\Enum\IsolationCode;
use App\Encounter\Domain\Enum\ReferralSource;
use App\Encounter\Domain\Enum\Sex;
use App\Import\Domain\Entity\Import;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Exactly one ED visit of a hospital (ADR 026, ADR 029).
 *
 * Identity: the internal `visitUid`; duplicates are detected by the keyed visit fingerprint, unique per
 * hospital and key generation (database constraint). The case key (pseudonym of the administrative
 * case number) only serves the suspected-duplicate check; it neither groups visits nor is a key.
 *
 * Lifecycle: an encounter belongs to the import that created it and is never changed by a later
 * import (no row versions, no replacement). Deleting or reprocessing that import removes it. New
 * encounters are written with `EncounterRepository::insertIfNew()`, never with the
 * EntityManager, so parallel imports cannot create the same visit twice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_visit', fields: ['hospital', 'keyGeneration', 'visitFingerprint'])]
#[ORM\UniqueConstraint(name: 'uniq_encounter_visit_uid', fields: ['visitUid'])]
#[ORM\Index(name: 'idx_encounter_case_key', fields: ['hospital', 'keyGeneration', 'caseKey'])]
#[ORM\Index(name: 'idx_encounter_hospital_anchor', fields: ['hospital', 'anchorAt'])]
#[ORM\Index(name: 'idx_encounter_hospital_route_anchor', fields: ['hospital', 'arrivalRoute', 'anchorAt'])]
class Encounter
{
    private const string HEX_HMAC_PATTERN = '/^[0-9a-f]{64}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private Uuid $visitUid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Hospital $hospital;

    #[ORM\Column(type: Types::INTEGER)]
    private int $keyGeneration;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $visitFingerprint;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $caseKey;

    /** The import that created this visit; a later import never takes it over (ADR 029). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Import $import;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AnchorSource::class)]
    private AnchorSource $anchorSource;

    /** Fingerprint anchor at minute precision as exchanged (first assessment contact, else ED admission). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $anchorAt;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $age;

    #[ORM\Column(type: Types::STRING, length: 1, enumType: Sex::class)]
    private Sex $sex;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: ArrivalMode::class)]
    private ?ArrivalMode $arrivalMode;

    /** Derived from the arrival mode and stored so that queries can filter on it (EMS, self, unknown). */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: ArrivalRoute::class)]
    private ArrivalRoute $arrivalRoute;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true, enumType: ReferralSource::class)]
    private ?ReferralSource $referralSource;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: AdmissionType::class)]
    private ?AdmissionType $admissionType;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: CaseStatus::class)]
    private ?CaseStatus $caseStatus;

    #[ORM\Embedded(class: EncounterTimes::class, columnPrefix: false)]
    private EncounterTimes $times;

    #[ORM\Embedded(class: Triage::class, columnPrefix: 'mts_')]
    private Triage $triage;

    #[ORM\Embedded(class: Retriage::class, columnPrefix: 'retriage_')]
    private Retriage $retriage;

    #[ORM\Embedded(class: EncounterDisposition::class, columnPrefix: 'disposition_')]
    private EncounterDisposition $disposition;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Department $admittingDepartment;

    #[ORM\Embedded(class: InitialVitals::class, columnPrefix: 'vitals_')]
    private InitialVitals $vitals;

    /** true = documented yes (`1` in the export); null = not documented (unknown). Never false (constructor type and check constraint). */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $workAccident;

    /** true = documented yes (`1` in the export); null = not documented (unknown). Never false (constructor type and check constraint). */
    #[ORM\Column(type: Types::BOOLEAN, nullable: true)]
    private ?bool $tetanusProtected;

    /** @var list<string> sorted, distinct {@see IsolationCode} values */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    private array $isolation;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<IsolationCode> $isolation
     */
    public function __construct(
        Hospital $hospital,
        Import $import,
        int $keyGeneration,
        string $visitFingerprint,
        string $caseKey,
        int $age,
        Sex $sex,
        EncounterTimes $times,
        ?ArrivalMode $arrivalMode = null,
        ?ReferralSource $referralSource = null,
        ?AdmissionType $admissionType = null,
        ?CaseStatus $caseStatus = null,
        Triage $triage = new Triage(),
        Retriage $retriage = new Retriage(),
        EncounterDisposition $disposition = new EncounterDisposition(),
        ?Department $admittingDepartment = null,
        InitialVitals $vitals = new InitialVitals(),
        ?true $workAccident = null,
        ?true $tetanusProtected = null,
        array $isolation = [],
    ) {
        if ($keyGeneration < 1) {
            throw new \InvalidArgumentException(sprintf('The key generation starts at 1, got %d.', $keyGeneration));
        }

        if (1 !== preg_match(self::HEX_HMAC_PATTERN, $visitFingerprint) || 1 !== preg_match(self::HEX_HMAC_PATTERN, $caseKey)) {
            throw new \InvalidArgumentException('Visit fingerprint and case key must be lowercase hex HMAC-SHA256 values.');
        }

        if ($age < 1 || $age > 99) {
            throw new \InvalidArgumentException(sprintf('Age must be 1–99 as in IVENA, got %d.', $age));
        }

        $anchorSource = $times->anchorSource();
        $anchorAt = $times->anchorAt();
        if (!$anchorSource instanceof AnchorSource || !$anchorAt instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('An encounter needs an ED admission or a first assessment contact as time anchor.');
        }

        $this->visitUid = Uuid::v7();
        $this->hospital = $hospital;
        $this->import = $import;
        $this->keyGeneration = $keyGeneration;
        $this->visitFingerprint = $visitFingerprint;
        $this->caseKey = $caseKey;
        $this->anchorSource = $anchorSource;
        $this->anchorAt = $anchorAt;
        $this->age = $age;
        $this->sex = $sex;
        $this->times = $times;
        $this->arrivalMode = $arrivalMode;
        $this->arrivalRoute = ArrivalRoute::fromArrivalMode($arrivalMode);
        $this->referralSource = $referralSource;
        $this->admissionType = $admissionType;
        $this->caseStatus = $caseStatus;
        $this->triage = $triage;
        $this->retriage = $retriage;
        $this->disposition = $disposition;
        $this->admittingDepartment = $admittingDepartment;
        $this->vitals = $vitals;
        $this->workAccident = $workAccident;
        $this->tetanusProtected = $tetanusProtected;
        $values = array_map(static fn (IsolationCode $code): string => $code->value, $isolation);
        sort($values);
        $this->isolation = array_values(array_unique($values));
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitUid(): Uuid
    {
        return $this->visitUid;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getKeyGeneration(): int
    {
        return $this->keyGeneration;
    }

    public function getVisitFingerprint(): string
    {
        return $this->visitFingerprint;
    }

    public function getCaseKey(): string
    {
        return $this->caseKey;
    }

    public function getImport(): Import
    {
        return $this->import;
    }

    public function getAnchorSource(): AnchorSource
    {
        return $this->anchorSource;
    }

    public function getAnchorAt(): \DateTimeImmutable
    {
        return $this->anchorAt;
    }

    public function getAge(): int
    {
        return $this->age;
    }

    public function getSex(): Sex
    {
        return $this->sex;
    }

    public function getArrivalMode(): ?ArrivalMode
    {
        return $this->arrivalMode;
    }

    public function getArrivalRoute(): ArrivalRoute
    {
        return $this->arrivalRoute;
    }

    public function getReferralSource(): ?ReferralSource
    {
        return $this->referralSource;
    }

    public function getAdmissionType(): ?AdmissionType
    {
        return $this->admissionType;
    }

    public function getCaseStatus(): ?CaseStatus
    {
        return $this->caseStatus;
    }

    public function getTimes(): EncounterTimes
    {
        return $this->times;
    }

    public function getTriage(): Triage
    {
        return $this->triage;
    }

    public function getRetriage(): Retriage
    {
        return $this->retriage;
    }

    public function getDisposition(): EncounterDisposition
    {
        return $this->disposition;
    }

    public function getAdmittingDepartment(): ?Department
    {
        return $this->admittingDepartment;
    }

    public function getVitals(): InitialVitals
    {
        return $this->vitals;
    }

    /**
     * true = documented yes, null = not documented (unknown).
     */
    public function getWorkAccident(): ?bool
    {
        return $this->workAccident;
    }

    /**
     * true = documented yes, null = not documented (unknown).
     */
    public function getTetanusProtected(): ?bool
    {
        return $this->tetanusProtected;
    }

    /**
     * @return list<IsolationCode>
     */
    public function getIsolation(): array
    {
        $codes = [];
        foreach ($this->isolation as $value) {
            $codes[] = IsolationCode::from($value);
        }

        return $codes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
