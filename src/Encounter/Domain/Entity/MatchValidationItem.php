<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Allocation;
use App\Encounter\Domain\Enum\MatchStatus;
use App\Encounter\Domain\Enum\ValidationStratum;
use App\Encounter\Domain\Enum\ValidationVerdict;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One drawn case of a match validation sample (#1057) and the reviewer's verdict.
 *
 * A pair item (automatic link, proposal) keeps the pair as sampled: status, score, points, pass and
 * matcher version, and the score band it was drawn from. An unlinked allocation has only the allocation,
 * an unlinked visit only the visit. The verdict is recorded with `decided_by`/`decided_at` and audited.
 *
 * Deletion: goes with its sample, and with its allocation or visit (ON DELETE CASCADE), like the pair rows.
 */
#[ORM\Entity]
#[Audit\Audited]
#[ORM\Table(name: 'encounter_validation_item')]
#[ORM\Index(name: 'idx_encounter_validation_item_sample', fields: ['sample', 'position'])]
#[ORM\Index(name: 'idx_encounter_validation_item_allocation', fields: ['allocation'])]
#[ORM\Index(name: 'idx_encounter_validation_item_encounter', fields: ['encounter'])]
class MatchValidationItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private MatchValidationSample $sample;

    /** Order in the sample (1-based). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(type: Types::STRING, length: 24, enumType: ValidationStratum::class)]
    private ValidationStratum $stratum;

    /** Score band of a pair item (label like "0.90–0.99"), null otherwise. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $band;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Allocation $allocation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Encounter $encounter;

    /** Status of the pair when sampled. */
    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: MatchStatus::class)]
    private ?MatchStatus $sampledStatus;

    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $score;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $points;

    #[ORM\Column(name: 'pass', type: Types::SMALLINT, nullable: true)]
    private ?int $pass;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $matcherVersion;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true, enumType: ValidationVerdict::class)]
    private ?ValidationVerdict $verdict = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    public function __construct(
        MatchValidationSample $sample,
        int $position,
        ValidationStratum $stratum,
        ?Allocation $allocation,
        ?Encounter $encounter,
        ?string $band = null,
        ?MatchStatus $sampledStatus = null,
        ?float $score = null,
        ?int $points = null,
        ?int $pass = null,
        ?string $matcherVersion = null,
    ) {
        $expected = match ($stratum) {
            ValidationStratum::Automatic, ValidationStratum::Proposal => !in_array(null, [$allocation, $encounter, $sampledStatus, $band], true),
            ValidationStratum::UnlinkedAllocation => $allocation instanceof Allocation && !$encounter instanceof Encounter,
            ValidationStratum::UnlinkedVisit => !$allocation instanceof Allocation && $encounter instanceof Encounter,
        };
        if (!$expected) {
            throw new \InvalidArgumentException(sprintf('Item of stratum "%s" has the wrong sides.', $stratum->value));
        }

        $this->sample = $sample;
        $this->position = $position;
        $this->stratum = $stratum;
        $this->allocation = $allocation;
        $this->encounter = $encounter;
        $this->band = $band;
        $this->sampledStatus = $sampledStatus;
        $this->score = $score;
        $this->points = $points;
        $this->pass = $pass;
        $this->matcherVersion = $matcherVersion;
    }

    /**
     * Records or replaces the verdict.
     */
    public function record(ValidationVerdict $verdict, User $decidedBy, \DateTimeImmutable $decidedAt): void
    {
        if (!\in_array($verdict, $this->stratum->verdicts(), true)) {
            throw new \InvalidArgumentException(sprintf('Verdict "%s" does not apply to stratum "%s".', $verdict->value, $this->stratum->value));
        }

        $this->verdict = $verdict;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = $decidedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSample(): MatchValidationSample
    {
        return $this->sample;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getStratum(): ValidationStratum
    {
        return $this->stratum;
    }

    public function getBand(): ?string
    {
        return $this->band;
    }

    public function getAllocation(): ?Allocation
    {
        return $this->allocation;
    }

    public function getEncounter(): ?Encounter
    {
        return $this->encounter;
    }

    public function getSampledStatus(): ?MatchStatus
    {
        return $this->sampledStatus;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function getPass(): ?int
    {
        return $this->pass;
    }

    public function getMatcherVersion(): ?string
    {
        return $this->matcherVersion;
    }

    public function getVerdict(): ?ValidationVerdict
    {
        return $this->verdict;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }
}
