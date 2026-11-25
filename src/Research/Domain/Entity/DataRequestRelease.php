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
 * recipient, the approved version and the exact query versions; it is valid for three months and leads to exactly one
 * export run once the recipient starts it.
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

    #[ORM\OneToOne(targetEntity: DataRequestExportRun::class, mappedBy: 'release', cascade: ['persist'])]
    private ?DataRequestExportRun $run = null;

    /**
     * @param list<DataRequestQueryVersion> $queryVersions
     * @param list<string>                  $excludedQueries
     *
     * @throws DataRequestException without queries, with a version that did not pass the check, or with single cases
     *                              the administrator did not confirm
     */
    public function __construct(DataRequest $request, int $number, User $recipient, array $queryVersions, array $excludedQueries, bool $singleCasesConfirmed, ?User $createdBy, \DateTimeImmutable $at)
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
    }

    /**
     * The recipient starts the export; the release is used and gets its one run.
     *
     * @throws DataRequestException when the release is not active, expired or not the recipient's
     */
    public function startExport(User $user, \DateTimeImmutable $at, string $locale): DataRequestExportRun
    {
        if (!$this->isStartableBy($user, $at)) {
            throw new DataRequestException('Only the recipient starts the export of an active release.');
        }

        $this->status = ReleaseStatus::Used;
        $this->run = new DataRequestExportRun($this, $at, $locale);

        return $this->run;
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

    public function getRun(): ?DataRequestExportRun
    {
        return $this->run;
    }
}
