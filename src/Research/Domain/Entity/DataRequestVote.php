<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\VoteChoice;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Board member's vote in one round (ADR 045). Members may change it until the round ends. Only the Board sees
 * single votes; a reason is an internal comment that refers to the vote (private for a requested revision).
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_vote')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_vote_member', columns: ['round_id', 'voter_id'])]
class DataRequestVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'votes')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequestRound $round;

    /** Null once the account is deleted; the vote stays as part of the decision. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $voter;

    #[ORM\Column(length: 16, enumType: VoteChoice::class)]
    private VoteChoice $choice;

    #[ORM\Column]
    private \DateTimeImmutable $castAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** An approval taken over from the previous round after a revision; the member may still change it. */
    #[ORM\Column]
    private bool $carriedOver;

    public function __construct(DataRequestRound $round, User $voter, VoteChoice $choice, \DateTimeImmutable $at, bool $carriedOver = false)
    {
        $this->round = $round;
        $this->voter = $voter;
        $this->castAt = $at;
        $this->carriedOver = $carriedOver;
        $this->change($choice, $at);
    }

    public function change(VoteChoice $choice, \DateTimeImmutable $at): void
    {
        $this->choice = $choice;
        $this->updatedAt = $at;
        if ($at > $this->castAt) {
            $this->carriedOver = false;
        }
    }

    public function isBy(User $member): bool
    {
        return $this->voter instanceof User && null !== $member->getId() && $this->voter->getId() === $member->getId();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): DataRequestRound
    {
        return $this->round;
    }

    public function getVoter(): ?User
    {
        return $this->voter;
    }

    public function getChoice(): VoteChoice
    {
        return $this->choice;
    }

    public function getCastAt(): \DateTimeImmutable
    {
        return $this->castAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isCarriedOver(): bool
    {
        return $this->carriedOver;
    }
}
