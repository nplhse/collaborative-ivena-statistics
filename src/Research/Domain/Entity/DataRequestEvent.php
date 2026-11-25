<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One step in the timeline of a data extract request (ADR 045): submitted, returned, vote opened, voted, round closed,
 * member excluded, veto resolved, expired, withdrawn. Rows are only added. The visibility follows the same levels as
 * comments, so a vote stays internal while the result of a round is public.
 */
#[ORM\Entity]
#[ORM\Table(name: 'data_request_event')]
#[ORM\Index(name: 'idx_data_request_event_request', columns: ['request_id', 'occurred_at'])]
class DataRequestEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'events')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    #[ORM\Column(length: 32, enumType: TimelineEventType::class)]
    private TimelineEventType $type;

    /** Who did it; null for the scheduler and for deleted accounts. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor;

    #[ORM\Column(length: 16, enumType: Visibility::class)]
    private Visibility $visibility;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    /** @var array<string, int|string> details for the timeline text, for example the version or the outcome */
    #[ORM\Column(type: Types::JSON)]
    private array $details;

    /**
     * @param array<string, int|string> $details
     */
    public function __construct(DataRequest $request, TimelineEventType $type, ?User $actor, Visibility $visibility, \DateTimeImmutable $occurredAt, array $details = [])
    {
        $this->request = $request;
        $this->type = $type;
        $this->actor = $actor;
        $this->visibility = $visibility;
        $this->occurredAt = $occurredAt;
        $this->details = $details;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DataRequest
    {
        return $this->request;
    }

    public function getType(): TimelineEventType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** @return array<string, int|string> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
