<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\Shared\Domain\Traits\HasRequiredPublicId;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A participant asks the Board for an extract from the database (#1635, ADR 045). The request carries the Board's
 * decision; its versions carry what the applicant entered. The `data_request` state machine moves the status through
 * the marking methods; this entity records the times and keeps versions frozen once submitted.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request')]
#[ORM\Index(name: 'idx_data_request_status', columns: ['status', 'submitted_at'])]
#[ORM\HasLifecycleCallbacks]
class DataRequest
{
    use HasRequiredPublicId;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Guards against two people changing the same request at the same time; Doctrine reads and raises it.
     *
     * @psalm-suppress UnusedProperty
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $lockVersion = 1;

    /** Null once the account is deleted; the request stays as a record of the decision. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $requester;

    #[ORM\Column(length: 32, enumType: DataRequestStatus::class)]
    private DataRequestStatus $status = DataRequestStatus::Draft;

    /** @var Collection<int, DataRequestVersion> */
    #[ORM\OneToMany(targetEntity: DataRequestVersion::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** First submission; a request without it was never visible to anyone but the applicant. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** When the request reached a final state or was approved. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    public function __construct(User $requester, DataRequestContent $content, \DateTimeImmutable $at)
    {
        $this->requester = $requester;
        $this->createdAt = $at;
        $this->versions = new ArrayCollection([new DataRequestVersion($this, 1, $content, $requester, $at)]);
    }

    /**
     * Read by the `data_request` state machine.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getMarking(): string
    {
        return $this->status->value;
    }

    /**
     * Written by the `data_request` state machine; call it only through the workflow.
     *
     * @param array<string, mixed> $context
     *
     * @psalm-suppress PossiblyUnusedMethod, PossiblyUnusedParam
     */
    public function setMarking(string $marking, array $context = []): void
    {
        $this->status = DataRequestStatus::from($marking);
    }

    /**
     * Changes the current version while it is not submitted yet.
     *
     * @throws DataRequestException when the current version is frozen
     */
    public function updateCurrentVersion(DataRequestContent $content, \DateTimeImmutable $at): void
    {
        $this->getCurrentVersion()->replaceContent($content, $at);
    }

    /** Freezes the current version after the `submit` transition. */
    public function recordSubmission(\DateTimeImmutable $at): void
    {
        $this->getCurrentVersion()->markSubmitted($at);
        $this->submittedAt ??= $at;
    }

    /** Records the time of a transition into a final state. */
    public function recordClosing(\DateTimeImmutable $at): void
    {
        $this->closedAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function isRequestedBy(User $user): bool
    {
        return $this->requester instanceof User && null !== $user->getId() && $this->requester->getId() === $user->getId();
    }

    public function getStatus(): DataRequestStatus
    {
        return $this->status;
    }

    /** @return list<DataRequestVersion> */
    public function getVersions(): array
    {
        return array_values($this->versions->toArray());
    }

    public function getCurrentVersion(): DataRequestVersion
    {
        $current = $this->versions->last();
        if (!$current instanceof DataRequestVersion) {
            throw new \LogicException('A data request always has a version.');
        }

        return $current;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /** Submitted at least once, so participants may see it. */
    public function wasSubmitted(): bool
    {
        return $this->submittedAt instanceof \DateTimeImmutable;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }
}
