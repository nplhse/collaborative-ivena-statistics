<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\DataRequestStatus;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\RoundKind;
use App\Research\Domain\Enum\TimelineEventType;
use App\Research\Domain\Enum\Visibility;
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

    /** @var Collection<int, DataRequestRound> */
    #[ORM\OneToMany(targetEntity: DataRequestRound::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $rounds;

    /** @var Collection<int, DataRequestExclusion> */
    #[ORM\OneToMany(targetEntity: DataRequestExclusion::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $exclusions;

    /** @var Collection<int, DataRequestComment> */
    #[ORM\OneToMany(targetEntity: DataRequestComment::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $comments;

    /** @var Collection<int, DataRequestEvent> */
    #[ORM\OneToMany(targetEntity: DataRequestEvent::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['occurredAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $events;

    /** @var Collection<int, DataRequestQuery> */
    #[ORM\OneToMany(targetEntity: DataRequestQuery::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $queries;

    /** @var Collection<int, DataRequestRelease> */
    #[ORM\OneToMany(targetEntity: DataRequestRelease::class, mappedBy: 'request', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $releases;

    /** The version the Board approved; with its export scope it is the decision. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?DataRequestVersion $approvedVersion = null;

    /** Until when the applicant may submit a revision the Board asked for. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revisionDeadline = null;

    /** Message to the applicant from the formal check or from an administrator resolving a veto. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $handbackMessage = null;

    public function __construct(User $requester, DataRequestContent $content, \DateTimeImmutable $at)
    {
        $this->requester = $requester;
        $this->createdAt = $at;
        $this->versions = new ArrayCollection([new DataRequestVersion($this, 1, $content, $requester, $at)]);
        $this->rounds = new ArrayCollection();
        $this->exclusions = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->events = new ArrayCollection();
        $this->queries = new ArrayCollection();
        $this->releases = new ArrayCollection();
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

    /** Starts the next version after the formal check returned the request or the Board asked for a revision. */
    public function addVersion(DataRequestContent $content, ?User $by, \DateTimeImmutable $at): void
    {
        if (!$this->getCurrentVersion()->isSubmitted()) {
            throw new DataRequestException('The current version is still open; change it instead.');
        }

        $this->versions->add(new DataRequestVersion($this, $this->getCurrentVersion()->getNumber() + 1, $content, $by, $at));
    }

    /**
     * Opens a voting round on the current, submitted version.
     *
     * @param list<int> $eligibleMemberIds
     *
     * @throws DataRequestException when a round is still open or the version is not submitted
     */
    public function openRound(RoundKind $kind, array $eligibleMemberIds, ?User $openedBy, \DateTimeImmutable $at, \DateTimeImmutable $deadline): DataRequestRound
    {
        if ($this->getOpenRound() instanceof DataRequestRound) {
            throw new DataRequestException('A voting round is still open.');
        }

        if (!$this->getCurrentVersion()->isSubmitted()) {
            throw new DataRequestException('The Board votes only on a submitted version.');
        }

        $round = new DataRequestRound($this, \count($this->rounds) + 1, $this->getCurrentVersion(), $kind, $eligibleMemberIds, $openedBy, $at, $deadline);
        $this->rounds->add($round);
        $this->revisionDeadline = null;
        $this->handbackMessage = null;

        return $round;
    }

    /**
     * @throws DataRequestException when the member is already excluded
     */
    public function exclude(User $member, string $reason, ?User $recordedBy, \DateTimeImmutable $at): void
    {
        if ($this->isExcluded($member)) {
            throw new DataRequestException('The member is already excluded.');
        }

        $this->exclusions->add(new DataRequestExclusion($this, $member, $reason, $recordedBy, $at));
        $this->getOpenRound()?->removeMember($member);
    }

    public function isExcluded(User $member): bool
    {
        foreach ($this->exclusions as $exclusion) {
            if (null !== $member->getId() && $exclusion->getMember()->getId() === $member->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws DataRequestException when the comment breaks the rules of DataRequestComment
     */
    public function addComment(?User $author, string $body, Visibility $visibility, ?DataRequestComment $parent, \DateTimeImmutable $at, ?DataRequestVote $vote = null): void
    {
        $this->comments->add(new DataRequestComment($this, $author, $body, $visibility, $parent, $at, $vote));
    }

    /**
     * @param array<string, int|string> $details
     */
    public function recordEvent(TimelineEventType $type, ?User $actor, Visibility $visibility, \DateTimeImmutable $at, array $details = []): void
    {
        $this->events->add(new DataRequestEvent($this, $type, $actor, $visibility, $at, $details));
    }

    /**
     * @throws DataRequestException when the name is taken or the query is invalid
     */
    public function addQuery(string $name, string $description, QueryKind $kind, string $sql, ?User $author, \DateTimeImmutable $at): DataRequestQuery
    {
        if ($this->findQuery($name) instanceof DataRequestQuery) {
            throw new DataRequestException('Another query of this request has that name.');
        }

        $query = new DataRequestQuery($this, $name, $description, $kind, $sql, $author, $at);
        $this->queries->add($query);

        return $query;
    }

    /**
     * Releases the current version of every query for the applicant (ADR 045).
     *
     * @throws DataRequestException when the request is not approved, has no applicant any more, a release is still
     *                              open or a query version is not checked
     */
    public function release(bool $singleCasesConfirmed, ?User $by, \DateTimeImmutable $at): DataRequestRelease
    {
        if (DataRequestStatus::Approved !== $this->status || !$this->requester instanceof User) {
            throw new DataRequestException('Only an approved request with an applicant is released.');
        }

        foreach ($this->releases as $release) {
            if ($release->isOpenAt($at)) {
                throw new DataRequestException('Another release of this request is still open.');
            }
        }

        $versions = array_map(static fn (DataRequestQuery $query): DataRequestQueryVersion => $query->getCurrentVersion(), $this->getQueries());
        $release = new DataRequestRelease($this, $this->releases->count() + 1, $this->requester, $versions, $singleCasesConfirmed, $by, $at);
        $this->releases->add($release);

        return $release;
    }

    /** @return list<DataRequestRelease> */
    public function getReleases(): array
    {
        return array_values($this->releases->toArray());
    }

    public function getLatestRelease(): ?DataRequestRelease
    {
        $release = $this->releases->last();

        return $release instanceof DataRequestRelease ? $release : null;
    }

    public function findRelease(int $number): ?DataRequestRelease
    {
        foreach ($this->releases as $release) {
            if ($release->getNumber() === $number) {
                return $release;
            }
        }

        return null;
    }

    public function findQuery(string $name): ?DataRequestQuery
    {
        foreach ($this->queries as $query) {
            if ($query->getName() === $name) {
                return $query;
            }
        }

        return null;
    }

    /** @return list<DataRequestQuery> */
    public function getQueries(): array
    {
        return array_values($this->queries->toArray());
    }

    /** @return list<DataRequestEvent> */
    public function getEvents(): array
    {
        return array_values($this->events->toArray());
    }

    public function recordApproval(DataRequestVersion $version, \DateTimeImmutable $at): void
    {
        $this->approvedVersion = $version;
        $this->closedAt = $at;
    }

    public function requestRevisionUntil(\DateTimeImmutable $deadline, ?string $message = null): void
    {
        $this->revisionDeadline = $deadline;
        $this->handbackMessage = $this->clean($message);
    }

    public function handBack(?string $message): void
    {
        $this->handbackMessage = $this->clean($message);
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

    /** @return list<DataRequestRound> */
    public function getRounds(): array
    {
        return array_values($this->rounds->toArray());
    }

    public function getOpenRound(): ?DataRequestRound
    {
        $last = $this->rounds->last();

        return $last instanceof DataRequestRound && $last->isOpen() ? $last : null;
    }

    public function getLastRound(): ?DataRequestRound
    {
        $last = $this->rounds->last();

        return $last instanceof DataRequestRound ? $last : null;
    }

    /** @return list<DataRequestComment> comments without a parent, oldest first; replies hang below them */
    public function getTopLevelComments(): array
    {
        return array_values(array_filter($this->comments->toArray(), static fn (DataRequestComment $comment): bool => !$comment->getParent() instanceof DataRequestComment));
    }

    public function findComment(int $id): ?DataRequestComment
    {
        foreach ($this->comments as $comment) {
            if ($comment->getId() === $id) {
                return $comment;
            }
        }

        return null;
    }

    /** @return list<DataRequestExclusion> */
    public function getExclusions(): array
    {
        return array_values($this->exclusions->toArray());
    }

    public function getApprovedVersion(): ?DataRequestVersion
    {
        return $this->approvedVersion;
    }

    public function getRevisionDeadline(): ?\DateTimeImmutable
    {
        return $this->revisionDeadline;
    }

    public function getHandbackMessage(): ?string
    {
        return $this->handbackMessage;
    }

    private function clean(?string $text): ?string
    {
        $text = null === $text ? '' : trim($text);

        return '' === $text ? null : $text;
    }
}
