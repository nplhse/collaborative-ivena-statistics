<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Encounter\Domain\Enum\AnchorSource;
use App\Encounter\Domain\Enum\MatchStatus;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One pair of ED visit and IVENA allocation with its matching result or manual decision (ADR 029, #1053).
 *
 * The row keeps how the pair was scored: score (agreement 0.0–1.0, not a probability), points per
 * feature, the pass, the matcher version, the time anchor used (ED admission, or the first assessment
 * contact as marked substitute) and the time difference. Automatic rows are written by the matcher
 * (`EncounterMatchRepository`), manual decisions by the review (#1054).
 *
 * Deletion: the row goes with its visit (ON DELETE CASCADE from `encounter`: import deletion, reprocess,
 * key change "delete") and with its allocation (ON DELETE CASCADE from `allocation`: deletion or
 * reprocess of the allocation import). Effective links are one-to-one (partial unique indexes); one row
 * per pair.
 */
#[ORM\Entity]
#[ORM\Table(name: 'encounter_match')]
#[ORM\UniqueConstraint(name: 'uniq_encounter_match_pair', fields: ['allocation', 'encounter'])]
#[ORM\UniqueConstraint(name: 'uniq_encounter_match_effective_allocation', fields: ['allocation'], options: ['where' => "(status IN ('auto_accepted', 'confirmed'))"])]
#[ORM\UniqueConstraint(name: 'uniq_encounter_match_effective_encounter', fields: ['encounter'], options: ['where' => "(status IN ('auto_accepted', 'confirmed'))"])]
#[ORM\Index(name: 'idx_encounter_match_encounter', fields: ['encounter'])]
#[ORM\Index(name: 'idx_encounter_match_hospital_status', fields: ['hospital', 'status'])]
class EncounterMatch
{
    /** Keys of the features of matcher v1, see MatchFeature. */
    private const array FEATURE_ORDER = ['time', 'sex', 'age', 'arrival_route', 'physician', 'work_accident'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Hospital $hospital;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Allocation $allocation;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Encounter $encounter;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: MatchStatus::class)]
    private MatchStatus $status;

    /** Set when two or more allocations reached the auto-accept threshold for this visit in the same pass. */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $conflict;

    /** Agreement 0.0–1.0 among the allocation's candidates and the "no match" option; null for a manual pair without matcher result. */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $score;

    /** Sum of the feature points. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $points;

    /** @var array<string, int> points per feature */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $contributions;

    #[ORM\Column(name: 'pass', type: Types::SMALLINT, nullable: true)]
    private ?int $pass;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $matcherVersion;

    /** Matching anchor: ED admission, or the first assessment contact as marked substitute. */
    #[ORM\Column(type: Types::STRING, length: 16, enumType: AnchorSource::class)]
    private AnchorSource $timeAnchor;

    /** Matching anchor minus IVENA arrival, in minutes. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $timeDifferenceMinutes;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, int> $contributions
     */
    public function __construct(
        Allocation $allocation,
        Encounter $encounter,
        MatchStatus $status,
        AnchorSource $timeAnchor,
        int $timeDifferenceMinutes,
        \DateTimeImmutable $createdAt,
        ?float $score = null,
        ?int $points = null,
        array $contributions = [],
        ?int $pass = null,
        ?string $matcherVersion = null,
        bool $conflict = false,
    ) {
        if ($allocation->getHospital()?->getId() !== $encounter->getHospital()->getId()) {
            throw new \InvalidArgumentException('Visit and allocation must belong to the same hospital.');
        }

        if ($status->isManual()) {
            throw new \InvalidArgumentException('A pair starts as automatic link or proposal; record a decision with decide().');
        }

        if (null !== $score && ($score < 0.0 || $score > 1.0)) {
            throw new \InvalidArgumentException(sprintf('The score lies between 0 and 1, got %s.', $score));
        }

        $this->hospital = $encounter->getHospital();
        $this->allocation = $allocation;
        $this->encounter = $encounter;
        $this->status = $status;
        $this->timeAnchor = $timeAnchor;
        $this->timeDifferenceMinutes = $timeDifferenceMinutes;
        $this->createdAt = $createdAt;
        $this->score = $score;
        $this->points = $points;
        $this->contributions = $contributions;
        $this->pass = $pass;
        $this->matcherVersion = $matcherVersion;
        $this->conflict = $conflict;
    }

    /**
     * Records a manual decision (confirm or reject); no matcher run changes it afterwards.
     *
     * @psalm-api Used by the match review (#1054).
     */
    public function decide(MatchStatus $decision, User $decidedBy, \DateTimeImmutable $decidedAt): void
    {
        if (!$decision->isManual()) {
            throw new \InvalidArgumentException('Only "confirmed" and "rejected" are decisions.');
        }

        $this->status = $decision;
        $this->decidedBy = $decidedBy;
        $this->decidedAt = $decidedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getAllocation(): Allocation
    {
        return $this->allocation;
    }

    public function getEncounter(): Encounter
    {
        return $this->encounter;
    }

    public function getStatus(): MatchStatus
    {
        return $this->status;
    }

    public function isConflict(): bool
    {
        return $this->conflict;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    /**
     * Points per feature in the order they were scored (JSONB does not keep the key order).
     *
     * @return array<string, int>
     */
    public function getContributions(): array
    {
        $ordered = [];
        foreach (self::FEATURE_ORDER as $feature) {
            if (\array_key_exists($feature, $this->contributions)) {
                $ordered[$feature] = $this->contributions[$feature];
            }
        }

        return $ordered + $this->contributions;
    }

    public function getPass(): ?int
    {
        return $this->pass;
    }

    public function getMatcherVersion(): ?string
    {
        return $this->matcherVersion;
    }

    public function getTimeAnchor(): AnchorSource
    {
        return $this->timeAnchor;
    }

    public function getTimeDifferenceMinutes(): int
    {
        return $this->timeDifferenceMinutes;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
