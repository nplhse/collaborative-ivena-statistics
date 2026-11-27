<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\Sex;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

/**
 * One treatment case of a hospital (ADR 037, #1300): the base data that clinical records of every schema
 * share, and the effective assignment to an IVENA allocation. Clinical records (an ED visit, an inpatient
 * stay, …) reference it through `encounter_id`; the database refuses to remove an encounter that a record
 * still references. An encounter can be left without records for a while (after an import deletion); the
 * cleanup of encounters without records removes it.
 *
 * The case key (pseudonym of the administrative case number) helps to find candidates; it is not the
 * identity of an encounter. The anchor is the time a schema created the encounter from (for `ed_visit` the
 * fingerprint anchor of ADR 029).
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_allocation', fields: ['allocation'])]
#[ORM\Index(name: 'idx_encounter_case_key', fields: ['hospital', 'keyGeneration', 'caseKey'])]
#[ORM\Index(name: 'idx_encounter_hospital_anchor', fields: ['hospital', 'anchorAt'])]
#[ORM\Index(name: 'idx_encounter_allocation_assigned_by', columns: ['allocation_assigned_by_id'])]
class Encounter
{
    private const string HEX_HMAC_PATTERN = '/^[0-9a-f]{64}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Hospital $hospital;

    #[ORM\Column(type: Types::INTEGER)]
    private int $keyGeneration;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $caseKey;

    /** Anchor at minute precision from the record that created the encounter. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $anchorAt;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $age;

    #[ORM\Column(type: Types::STRING, length: 1, enumType: Sex::class)]
    private Sex $sex;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Effective assignment to an IVENA allocation (#1300, ADR 037), at most one encounter per allocation. Set
     * and removed only through EncounterAllocationAssignments; the ED matching keeps its candidates and
     * decisions in its own history (`encounter_match`).
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Allocation $allocation = null;

    /** @psalm-suppress UnusedProperty Written with SQL by EncounterAllocationAssignments. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $allocationAssignedAt = null;

    /** @psalm-suppress UnusedProperty Written with SQL by EncounterAllocationAssignments. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $allocationAssignedBy = null;

    public function __construct(Hospital $hospital, int $keyGeneration, string $caseKey, \DateTimeImmutable $anchorAt, int $age, Sex $sex)
    {
        if ($keyGeneration < 1) {
            throw new \InvalidArgumentException(sprintf('The key generation starts at 1, got %d.', $keyGeneration));
        }

        if (1 !== preg_match(self::HEX_HMAC_PATTERN, $caseKey)) {
            throw new \InvalidArgumentException('The case key must be a lowercase hex HMAC-SHA256 value.');
        }

        if ($age < 1 || $age > 99) {
            throw new \InvalidArgumentException(sprintf('Age must be 1–99 as in IVENA, got %d.', $age));
        }

        $this->hospital = $hospital;
        $this->keyGeneration = $keyGeneration;
        $this->caseKey = $caseKey;
        $this->anchorAt = $anchorAt;
        $this->age = $age;
        $this->sex = $sex;
        $this->createdAt = Clock::get()->now();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getKeyGeneration(): int
    {
        return $this->keyGeneration;
    }

    public function getCaseKey(): string
    {
        return $this->caseKey;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getAllocation(): ?Allocation
    {
        return $this->allocation;
    }
}
