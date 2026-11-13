<?php

declare(strict_types=1);

namespace App\Encounter\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A stratified review sample of one hospital and period for the match validation (#1057, ADR 030).
 *
 * Keeps how it was drawn (period, size per stratum and score band, seed, score bands, matcher version)
 * and the population of every stratum and band at that moment, so its report can weight the bands.
 * The items ({@see MatchValidationItem}) keep the pair as it was sampled; later decisions or reruns do
 * not change what the sample measured.
 *
 * Deletion: goes with its hospital (ON DELETE CASCADE); its items go with it.
 */
#[ORM\Entity]
#[Audit\Audited]
#[ORM\Table(name: 'encounter_validation_sample')]
#[ORM\Index(name: 'idx_encounter_validation_sample_hospital', fields: ['hospital'])]
class MatchValidationSample
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Hospital $hospital;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodFrom;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodTo;

    /** Items drawn per stratum and score band (at most). */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $sampleSize;

    #[ORM\Column(type: Types::INTEGER)]
    private int $seed;

    /** Version of the production matcher when the sample was drawn. */
    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $matcherVersion;

    /** @var list<float> lower bounds of the score bands */
    #[ORM\Column(type: Types::JSON)]
    private array $scoreBands;

    /** @var array<string, int> population per cell ("stratum" or "stratum|band") when drawn */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $population;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<float>        $scoreBands
     * @param array<string, int> $population
     */
    public function __construct(
        Hospital $hospital,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        int $sampleSize,
        int $seed,
        string $matcherVersion,
        array $scoreBands,
        array $population,
        ?User $createdBy,
        \DateTimeImmutable $createdAt,
    ) {
        if ($periodTo < $periodFrom) {
            throw new \InvalidArgumentException('The period ends before it starts.');
        }
        if ($sampleSize < 1) {
            throw new \InvalidArgumentException('A sample draws at least one item per stratum.');
        }

        $this->hospital = $hospital;
        $this->periodFrom = $periodFrom->setTime(0, 0);
        $this->periodTo = $periodTo->setTime(0, 0);
        $this->sampleSize = $sampleSize;
        $this->seed = $seed;
        $this->matcherVersion = $matcherVersion;
        $this->scoreBands = $scoreBands;
        $this->population = $population;
        $this->createdBy = $createdBy;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHospital(): Hospital
    {
        return $this->hospital;
    }

    public function getPeriodFrom(): \DateTimeImmutable
    {
        return $this->periodFrom;
    }

    public function getPeriodTo(): \DateTimeImmutable
    {
        return $this->periodTo;
    }

    public function getSampleSize(): int
    {
        return $this->sampleSize;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function getMatcherVersion(): string
    {
        return $this->matcherVersion;
    }

    /**
     * @return list<float>
     */
    public function getScoreBands(): array
    {
        return $this->scoreBands;
    }

    /**
     * @return array<string, int>
     */
    public function getPopulation(): array
    {
        return $this->population;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
