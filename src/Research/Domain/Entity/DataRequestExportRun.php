<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\ExportRunStatus;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The one export run of a release (ADR 045). The worker runs all query versions of the release in one read-only
 * transaction and packs the files into an archive that the recipient may download for seven days. The internal record
 * proves what ran, on which data and with which result; it never leaves the application.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_export_run')]
#[ORM\Index(name: 'idx_data_request_export_run_status', columns: ['status', 'available_until'])]
class DataRequestExportRun
{
    public const string DOWNLOAD_PERIOD = '+7 days';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Two workers handling the same message: only the first takes the queued run over; Doctrine reads and raises it.
     *
     * @psalm-suppress UnusedProperty
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER)]
    private int $lockVersion = 1;

    /** Exactly one run per release; parallel starts and worker retries meet this unique key. */
    #[ORM\OneToOne(targetEntity: DataRequestRelease::class, inversedBy: 'run')]
    #[ORM\JoinColumn(unique: true, nullable: false, onDelete: 'CASCADE')]
    private DataRequestRelease $release;

    #[ORM\Column(length: 16, enumType: ExportRunStatus::class)]
    private ExportRunStatus $status = ExportRunStatus::Queued;

    #[ORM\Column]
    private \DateTimeImmutable $queuedAt;

    /** Language of the read-me: the recipient's when they started the export. */
    #[ORM\Column(length: 8)]
    private string $locale;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $availableUntil = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts = 0;

    /** Technical error of the last attempt, for administrators. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /** File name of the archive in the export storage; null before it exists and after the cleanup. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $archiveFile = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $archiveBytes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $archiveChecksum = null;

    /** @var array<string, mixed> internal record: settings, query versions, data state, files, counts per group */
    #[ORM\Column(type: Types::JSON)]
    private array $record = [];

    /** @var Collection<int, DataRequestExportDownload> */
    #[ORM\OneToMany(targetEntity: DataRequestExportDownload::class, mappedBy: 'run', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['downloadedAt' => 'ASC', 'id' => 'ASC'])]
    private Collection $downloads;

    public function __construct(DataRequestRelease $release, \DateTimeImmutable $at, string $locale)
    {
        $this->release = $release;
        $this->queuedAt = $at;
        $this->locale = $locale;
        $this->downloads = new ArrayCollection();
    }

    /**
     * The worker takes over a queued run.
     *
     * @throws DataRequestException when the run is not queued
     */
    public function start(\DateTimeImmutable $at): void
    {
        if (ExportRunStatus::Queued !== $this->status) {
            throw new DataRequestException('Only a queued export starts.');
        }

        $this->status = ExportRunStatus::Running;
        $this->startedAt = $at;
        $this->error = null;
        ++$this->attempts;
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws DataRequestException when the run is not running
     */
    public function provide(string $archiveFile, int $bytes, string $checksum, array $record, \DateTimeImmutable $at): void
    {
        $this->finish(ExportRunStatus::Available, $record, $at);
        $this->archiveFile = $archiveFile;
        $this->archiveBytes = $bytes;
        $this->archiveChecksum = $checksum;
        $this->availableUntil = $at->modify(self::DOWNLOAD_PERIOD);
    }

    /**
     * A group fell below the minimum of hospitals; nothing is provided. A changed query needs a new release.
     *
     * @param array<string, mixed> $record
     *
     * @throws DataRequestException when the run is not running
     */
    public function block(array $record, \DateTimeImmutable $at): void
    {
        $this->finish(ExportRunStatus::Blocked, $record, $at);
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws DataRequestException when the run is not running
     */
    public function fail(string $error, array $record, \DateTimeImmutable $at): void
    {
        $this->finish(ExportRunStatus::Failed, $record, $at);
        $this->error = mb_substr($error, 0, 2000);
    }

    /**
     * A failed run is queued again; it stays the same run.
     *
     * @throws DataRequestException when the run did not fail
     */
    public function retry(\DateTimeImmutable $at): void
    {
        if (ExportRunStatus::Failed !== $this->status) {
            throw new DataRequestException('Only a failed export is tried again.');
        }

        $this->status = ExportRunStatus::Queued;
        $this->queuedAt = $at;
        $this->finishedAt = null;
    }

    /**
     * The download period ended: no more downloads, the archive goes. Returns the archive's file name to remove.
     *
     * @throws DataRequestException when the run is not available or its period has not ended
     */
    public function expire(\DateTimeImmutable $at): ?string
    {
        if (ExportRunStatus::Available !== $this->status || !$this->availableUntil instanceof \DateTimeImmutable || $at < $this->availableUntil) {
            throw new DataRequestException('Only an available export whose download period ended expires.');
        }

        $archive = $this->archiveFile;
        $this->status = ExportRunStatus::Expired;
        $this->archiveFile = null;

        return $archive;
    }

    /**
     * The request was withdrawn: a queued, running or available export ends without (further) result. Returns the
     * archive to remove, if there is one.
     */
    public function cancel(\DateTimeImmutable $at): ?string
    {
        if (!$this->status->isPending() && ExportRunStatus::Available !== $this->status) {
            return null;
        }

        $archive = $this->archiveFile;
        $this->status = ExportRunStatus::Cancelled;
        $this->archiveFile = null;
        $this->finishedAt ??= $at;

        return $archive;
    }

    public function isDownloadableAt(\DateTimeImmutable $at): bool
    {
        return ExportRunStatus::Available === $this->status && null !== $this->archiveFile
            && $this->availableUntil instanceof \DateTimeImmutable && $at < $this->availableUntil;
    }

    public function recordDownload(?User $user, \DateTimeImmutable $at): void
    {
        $this->downloads->add(new DataRequestExportDownload($this, $user, $at));
    }

    /**
     * @param array<string, mixed> $record
     *
     * @throws DataRequestException when the run is not running
     */
    private function finish(ExportRunStatus $status, array $record, \DateTimeImmutable $at): void
    {
        if (ExportRunStatus::Running !== $this->status) {
            throw new DataRequestException('Only a running export finishes.');
        }

        $this->status = $status;
        $this->record = $record;
        $this->finishedAt = $at;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRelease(): DataRequestRelease
    {
        return $this->release;
    }

    public function getStatus(): ExportRunStatus
    {
        return $this->status;
    }

    public function getQueuedAt(): \DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getAvailableUntil(): ?\DateTimeImmutable
    {
        return $this->availableUntil;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getArchiveFile(): ?string
    {
        return $this->archiveFile;
    }

    public function getArchiveBytes(): ?int
    {
        return $this->archiveBytes;
    }

    public function getArchiveChecksum(): ?string
    {
        return $this->archiveChecksum;
    }

    /** @return array<string, mixed> */
    public function getRecord(): array
    {
        return $this->record;
    }

    /** @return list<DataRequestExportDownload> */
    public function getDownloads(): array
    {
        return array_values($this->downloads->toArray());
    }
}
