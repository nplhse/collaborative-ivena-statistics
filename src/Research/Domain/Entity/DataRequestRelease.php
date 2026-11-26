<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Enum\ReleaseStatus;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * An administrator releases an approved data extract request for its applicant (ADR 045). The release binds the
 * recipient, the approved version, the exact query versions and the conditions; it is valid for three months and leads
 * to exactly one export run once the recipient accepts the conditions and starts it. It ends as used, expired after
 * three months, or revoked by an administrator or by the withdrawal of the request.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_release')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_release_number', columns: ['request_id', 'number'])]
class DataRequestRelease
{
    public const string VALIDITY = '+3 months';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'releases')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $number;

    /** Null once the account is deleted; nobody can start or download the export then. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $recipient;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $validUntil;

    #[ORM\Column(length: 16, enumType: ReleaseStatus::class)]
    private ReleaseStatus $status = ReleaseStatus::Active;

    /** For single-case queries: the administrator confirmed coarse timestamps and age groups. */
    #[ORM\Column]
    private bool $singleCasesConfirmed;

    /** @var list<string> names of the request's queries this release leaves out on purpose */
    #[ORM\Column(type: Types::JSON)]
    private array $excludedQueries;

    /** @var Collection<int, DataRequestQueryVersion> */
    #[ORM\ManyToMany(targetEntity: DataRequestQueryVersion::class)]
    #[ORM\JoinTable(name: 'data_request_release_query_version')]
    #[ORM\JoinColumn(name: 'release_id', nullable: false, onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'query_version_id', nullable: false)]
    private Collection $queryVersions;

    /** Conditions the recipient accepts before starting the export; null for none. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $conditions = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $conditionsAcceptedAt = null;

    /** SHA-256 of the conditions as accepted, like the version of accepted terms of use. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $conditionsAcceptedChecksum = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /** Why an administrator revoked the release; null when it expired or ended with the withdrawal. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $revocationReason = null;

    #[ORM\OneToOne(targetEntity: DataRequestExportRun::class, mappedBy: 'release', cascade: ['persist'])]
    private ?DataRequestExportRun $run = null;

    /**
     * @param list<DataRequestQueryVersion> $queryVersions
     * @param list<string>                  $excludedQueries
     *
     * @throws DataRequestException without queries, with a version that did not pass the check, or with single cases
     *                              the administrator did not confirm
     */
    public function __construct(DataRequest $request, int $number, User $recipient, array $queryVersions, array $excludedQueries, bool $singleCasesConfirmed, ?User $createdBy, \DateTimeImmutable $at, ?string $conditions = null)
    {
        if ([] === $queryVersions) {
            throw new DataRequestException('A release needs at least one query.');
        }

        foreach ($queryVersions as $version) {
            if (!$version->passesCheck()) {
                throw new DataRequestException('Every query version of a release must pass the allowlist check.');
            }

            if (QueryKind::SingleCases === $version->getQuery()->getKind() && !$singleCasesConfirmed) {
                throw new DataRequestException('Single cases need the confirmation of coarse timestamps and age groups.');
            }
        }

        $this->request = $request;
        $this->number = $number;
        $this->recipient = $recipient;
        $this->queryVersions = new ArrayCollection($queryVersions);
        $this->excludedQueries = $excludedQueries;
        $this->singleCasesConfirmed = $singleCasesConfirmed;
        $this->createdBy = $createdBy;
        $this->createdAt = $at;
        $this->validUntil = $at->modify(self::VALIDITY);
        $conditions = null === $conditions ? '' : trim(str_replace("\r\n", "\n", $conditions));
        $this->conditions = '' === $conditions ? null : $conditions;
    }

    /**
     * The recipient starts the export; the release is used and gets its one run. With conditions the recipient
     * accepts them in the same step; their checksum records the wording accepted.
     *
     * @throws DataRequestException when the release is not active, expired or not the recipient's, or the conditions
     *                              are not accepted
     */
    public function startExport(User $user, \DateTimeImmutable $at, string $locale, bool $acceptsConditions = false): DataRequestExportRun
    {
        if (!$this->isStartableBy($user, $at)) {
            throw new DataRequestException('Only the recipient starts the export of an active release.');
        }

        if (null !== $this->conditions) {
            if (!$acceptsConditions) {
                throw new DataRequestException('The recipient accepts the conditions before the export starts.');
            }

            $this->conditionsAcceptedAt = $at;
            $this->conditionsAcceptedChecksum = hash('sha256', $this->conditions);
        }

        $this->status = ReleaseStatus::Used;
        $this->run = new DataRequestExportRun($this, $at, $locale);

        return $this->run;
    }

    /**
     * An administrator revokes a release whose export was not started, for example after a mistake.
     *
     * @throws DataRequestException when the release is not active or the reason is empty
     */
    public function revoke(string $reason, \DateTimeImmutable $at): void
    {
        $reason = trim($reason);
        if (ReleaseStatus::Active !== $this->status || '' === $reason) {
            throw new DataRequestException('Only an active release is revoked, with a reason.');
        }

        $this->status = ReleaseStatus::Revoked;
        $this->revocationReason = $reason;
        $this->endedAt = $at;
    }

    /**
     * Three months passed without an export.
     *
     * @throws DataRequestException when the release is not active or still valid
     */
    public function expire(\DateTimeImmutable $at): void
    {
        if (ReleaseStatus::Active !== $this->status || $at < $this->validUntil) {
            throw new DataRequestException('Only an active release whose three months passed expires.');
        }

        $this->status = ReleaseStatus::Expired;
        $this->endedAt = $at;
    }

    /**
     * The applicant withdrew the request: an active release is revoked, an export that is queued, running or
     * downloadable is cancelled. Returns the archive to remove, if there is one.
     */
    public function endWithWithdrawal(\DateTimeImmutable $at): ?string
    {
        if (ReleaseStatus::Active === $this->status) {
            $this->status = ReleaseStatus::Revoked;
            $this->endedAt = $at;
        }

        return $this->run?->cancel($at);
    }

    public function isStartableBy(User $user, \DateTimeImmutable $at): bool
    {
        return ReleaseStatus::Active === $this->status && !$this->run instanceof DataRequestExportRun && $at < $this->validUntil && $this->isFor($user);
    }

    public function isFor(User $user): bool
    {
        return $this->recipient instanceof User && null !== $user->getId() && $this->recipient->getId() === $user->getId();
    }

    /** Active, or used with an export that is still under way or downloadable: no second release then. */
    public function isOpenAt(\DateTimeImmutable $at): bool
    {
        if (ReleaseStatus::Active === $this->status) {
            return $at < $this->validUntil;
        }

        return $this->run instanceof DataRequestExportRun && ($this->run->getStatus()->isPending() || $this->run->isDownloadableAt($at));
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

    public function getRecipient(): ?User
    {
        return $this->recipient;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getValidUntil(): \DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function getStatus(): ReleaseStatus
    {
        return $this->status;
    }

    public function isSingleCasesConfirmed(): bool
    {
        return $this->singleCasesConfirmed;
    }

    /**
     * In the order of the queries, so the files of an export always come in the same order.
     *
     * @return list<DataRequestQueryVersion>
     */
    public function getQueryVersions(): array
    {
        $versions = array_values($this->queryVersions->toArray());
        usort($versions, static fn (DataRequestQueryVersion $a, DataRequestQueryVersion $b): int => [$a->getQuery()->getId(), $a->getQuery()->getName()] <=> [$b->getQuery()->getId(), $b->getQuery()->getName()]);

        return $versions;
    }

    /** @return list<string> */
    public function getExcludedQueries(): array
    {
        return $this->excludedQueries;
    }

    public function getConditions(): ?string
    {
        return $this->conditions;
    }

    public function getConditionsAcceptedAt(): ?\DateTimeImmutable
    {
        return $this->conditionsAcceptedAt;
    }

    public function getConditionsAcceptedChecksum(): ?string
    {
        return $this->conditionsAcceptedChecksum;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function getRevocationReason(): ?string
    {
        return $this->revocationReason;
    }

    public function getRun(): ?DataRequestExportRun
    {
        return $this->run;
    }
}
