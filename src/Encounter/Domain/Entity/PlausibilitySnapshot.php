<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Encounter\Domain\Enum\PlausibilitySnapshotOrigin;
use App\Encounter\Domain\Enum\PlausibilitySnapshotStatus;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An immutable set of plausibility cells and PZC → problem-area memberships (ADR 033). The matcher (#1154) reads the
 * active snapshot only; without one the bonus is 0 everywhere.
 *
 * Status goes `draft` → `active` → `retired`; a partial unique index allows one active snapshot. Cells and memberships
 * change only while the snapshot is a draft.
 *
 * A learned snapshot (#1155) records what it was learned from: the period of the eligible pairs (IVENA arrival days),
 * their number over all hospitals, the learning parameters and the report with replay figures per hospital
 * (PlausibilityLearningReport). The count and the report are internal: pages show replay figures only for the
 * hospitals the viewer may review (ADR 033, Privacy). The report is not audited (bulk figures); the learning itself is
 * audited as `encounter.plausibility.snapshot_learned`.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'encounter_plausibility_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_plausibility_snapshot_active', fields: ['status'], options: ['where' => "((status)::text = 'active'::text)"])]
class PlausibilitySnapshot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: PlausibilitySnapshotStatus::class)]
    private PlausibilitySnapshotStatus $status = PlausibilitySnapshotStatus::Draft;

    #[ORM\Column(length: 16, enumType: PlausibilitySnapshotOrigin::class)]
    private PlausibilitySnapshotOrigin $origin;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $activatedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retiredAt = null;

    /** First IVENA arrival day of the eligible pairs of a learned snapshot. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $learnedFrom = null;

    /** Last IVENA arrival day of the eligible pairs of a learned snapshot. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $learnedTo = null;

    /** Eligible pairs over all hospitals; never shown to reviewers (ADR 033, Privacy). */
    #[ORM\Column(nullable: true)]
    private ?int $eligiblePairs = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $learningParameters = null;

    /** @var array<array-key, mixed>|null */
    #[Audit\AuditIgnore]
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    private ?array $report = null;

    public function __construct(PlausibilitySnapshotOrigin $origin, \DateTimeImmutable $createdAt)
    {
        $this->origin = $origin;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): PlausibilitySnapshotStatus
    {
        return $this->status;
    }

    public function isDraft(): bool
    {
        return PlausibilitySnapshotStatus::Draft === $this->status;
    }

    public function isActive(): bool
    {
        return PlausibilitySnapshotStatus::Active === $this->status;
    }

    public function getOrigin(): PlausibilitySnapshotOrigin
    {
        return $this->origin;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActivatedAt(): ?\DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function getRetiredAt(): ?\DateTimeImmutable
    {
        return $this->retiredAt;
    }

    public function getLearnedFrom(): ?\DateTimeImmutable
    {
        return $this->learnedFrom;
    }

    public function getLearnedTo(): ?\DateTimeImmutable
    {
        return $this->learnedTo;
    }

    public function getEligiblePairs(): ?int
    {
        return $this->eligiblePairs;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLearningParameters(): ?array
    {
        return $this->learningParameters;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function getReport(): ?array
    {
        return $this->report;
    }

    /**
     * Records what a learned draft was learned from and its report (#1155).
     *
     * @param array<string, mixed>    $parameters
     * @param array<array-key, mixed> $report
     */
    public function recordLearning(\DateTimeImmutable $from, \DateTimeImmutable $to, int $eligiblePairs, array $parameters, array $report): void
    {
        if (!$this->isDraft() || PlausibilitySnapshotOrigin::Learned !== $this->origin) {
            throw new \LogicException('Only a learned draft records what it was learned from.');
        }

        if ($to < $from || $eligiblePairs < 0) {
            throw new \InvalidArgumentException('Expected a period in order and a non-negative number of eligible pairs.');
        }

        $this->learnedFrom = $from;
        $this->learnedTo = $to;
        $this->eligiblePairs = $eligiblePairs;
        $this->learningParameters = $parameters;
        $this->report = $report;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        if (!$this->isDraft()) {
            throw new \LogicException('Only a draft plausibility snapshot can be activated.');
        }

        $this->status = PlausibilitySnapshotStatus::Active;
        $this->activatedAt = $now;
    }

    public function retire(\DateTimeImmutable $now): void
    {
        if (!$this->isActive()) {
            throw new \LogicException('Only the active plausibility snapshot can be retired.');
        }

        $this->status = PlausibilitySnapshotStatus::Retired;
        $this->retiredAt = $now;
    }
}
