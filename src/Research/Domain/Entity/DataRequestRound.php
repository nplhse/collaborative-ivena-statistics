<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\RoundOutcome;
use App\Research\Domain\Enum\VoteChoice;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\Service\RoundEvaluator;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One Board vote on one version of a data extract request (ADR 045). The eligible members are fixed when the round
 * opens; a member excluded for a conflict of interest later leaves the round together with their vote.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_round')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_round_number', columns: ['request_id', 'number'])]
#[ORM\Index(name: 'idx_data_request_round_open', columns: ['closed_at', 'deadline'])]
class DataRequestRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $number;

    /** The version the members vote on. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequestVersion $version;

    #[ORM\Column(length: 16, enumType: RoundKind::class)]
    private RoundKind $kind;

    #[ORM\Column]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column]
    private \DateTimeImmutable $deadline;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $openedBy;

    /** @var list<int> ids of the members eligible to vote in this round */
    #[ORM\Column(type: Types::JSON)]
    private array $eligibleMemberIds;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 32, nullable: true, enumType: RoundOutcome::class)]
    private ?RoundOutcome $outcome = null;

    /** @var Collection<int, DataRequestVote> */
    #[ORM\OneToMany(targetEntity: DataRequestVote::class, mappedBy: 'round', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $votes;

    /**
     * @param list<int> $eligibleMemberIds
     */
    public function __construct(DataRequest $request, int $number, DataRequestVersion $version, RoundKind $kind, array $eligibleMemberIds, ?User $openedBy, \DateTimeImmutable $openedAt, \DateTimeImmutable $deadline)
    {
        if ([] === $eligibleMemberIds) {
            throw new DataRequestException('A voting round needs at least one eligible member.');
        }

        $this->request = $request;
        $this->number = $number;
        $this->version = $version;
        $this->kind = $kind;
        $this->eligibleMemberIds = array_values(array_unique($eligibleMemberIds));
        $this->openedBy = $openedBy;
        $this->openedAt = $openedAt;
        $this->deadline = $deadline;
        $this->votes = new ArrayCollection();
    }

    /**
     * Casts or changes the member's vote while the round is open.
     *
     * @throws DataRequestException when the round is closed or the member is not eligible
     */
    public function castVote(User $member, VoteChoice $choice, \DateTimeImmutable $at, bool $carriedOver = false): void
    {
        $this->assertOpen();
        if (!$this->isEligible($member)) {
            throw new DataRequestException('Only eligible members vote in this round.');
        }

        $vote = $this->voteOf($member);
        if ($vote instanceof DataRequestVote) {
            $vote->change($choice, $at);

            return;
        }

        $this->votes->add(new DataRequestVote($this, $member, $choice, $at, $carriedOver));
    }

    /** Removes an excluded member and their vote from the open round. */
    public function removeMember(User $member): void
    {
        if (!$this->isOpen()) {
            return;
        }

        $this->eligibleMemberIds = array_values(array_filter($this->eligibleMemberIds, static fn (int $id): bool => $id !== $member->getId()));
        $vote = $this->voteOf($member);
        if ($vote instanceof DataRequestVote) {
            $this->votes->removeElement($vote);
        }
    }

    public function close(RoundOutcome $outcome, \DateTimeImmutable $at): void
    {
        $this->assertOpen();
        $this->outcome = $outcome;
        $this->closedAt = $at;
    }

    public function isEligible(User $member): bool
    {
        return null !== $member->getId() && \in_array($member->getId(), $this->eligibleMemberIds, true);
    }

    public function voteOf(User $member): ?DataRequestVote
    {
        foreach ($this->votes as $vote) {
            if ($vote->isBy($member)) {
                return $vote;
            }
        }

        return null;
    }

    /** @return list<VoteChoice> current choices of members who are still eligible */
    public function choices(): array
    {
        $choices = [];
        foreach ($this->votes as $vote) {
            $voterId = $vote->getVoter()?->getId();
            if (null !== $voterId && \in_array($voterId, $this->eligibleMemberIds, true)) {
                $choices[] = $vote->getChoice();
            }
        }

        return $choices;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DataRequest
    {
        return $this->request;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getVersion(): DataRequestVersion
    {
        return $this->version;
    }

    public function getKind(): RoundKind
    {
        return $this->kind;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getDeadline(): \DateTimeImmutable
    {
        return $this->deadline;
    }

    public function getOpenedBy(): ?User
    {
        return $this->openedBy;
    }

    /** @return list<int> */
    public function getEligibleMemberIds(): array
    {
        return $this->eligibleMemberIds;
    }

    public function getEligibleCount(): int
    {
        return \count($this->eligibleMemberIds);
    }

    /** Votes needed for a valid round: two thirds of the eligible members, rounded up. */
    public function getQuorum(): int
    {
        return RoundEvaluator::quorum($this->getEligibleCount());
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getOutcome(): ?RoundOutcome
    {
        return $this->outcome;
    }

    public function isOpen(): bool
    {
        return !$this->closedAt instanceof \DateTimeImmutable;
    }

    /** @return list<DataRequestVote> */
    public function getVotes(): array
    {
        return array_values($this->votes->toArray());
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw new DataRequestException('The voting round is closed.');
        }
    }
}
