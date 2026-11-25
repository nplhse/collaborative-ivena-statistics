<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Board member is excluded from a data extract request for a conflict of interest (ADR 045). The exclusion holds for
 * every round of the request: the member does not vote, does not count for the quorum and cannot do the formal check.
 * Declared by the member or recorded by an administrator, with a reason the Board sees.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_exclusion')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_exclusion_member', columns: ['request_id', 'member_id'])]
class DataRequestExclusion
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'exclusions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $member;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recordedBy;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct(DataRequest $request, User $member, string $reason, ?User $recordedBy, \DateTimeImmutable $recordedAt)
    {
        $this->request = $request;
        $this->member = $member;
        $this->reason = trim($reason);
        $this->recordedBy = $recordedBy;
        $this->recordedAt = $recordedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DataRequest
    {
        return $this->request;
    }

    public function getMember(): User
    {
        return $this->member;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getRecordedBy(): ?User
    {
        return $this->recordedBy;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
