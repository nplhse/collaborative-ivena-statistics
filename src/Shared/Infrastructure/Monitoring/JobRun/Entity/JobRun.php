<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Monitoring\JobRun\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One run (one attempt) of a long-running background job (ADR 018). Rows are written via DBAL by
 * JobRunRecorder; the entity only maps the table so the admin area can list them.
 *
 * Job, trigger and status stay plain strings: a value written by a newer job must never break hydration.
 * Import and hospital are ids without foreign keys, so runs stay readable after either is deleted.
 * The migration creates idx_job_run_job_started with started_at DESC; Doctrine does not model the order.
 *
 * @psalm-suppress ClassMustBeFinal
 * @psalm-suppress UnusedClass Doctrine-mapped; written via DBAL.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'job_run')]
#[ORM\Index(name: 'idx_job_run_job_started', columns: ['job', 'started_at'])]
#[ORM\Index(name: 'idx_job_run_import', columns: ['import_id'])]
#[ORM\Index(name: 'idx_job_run_running', columns: ['job'], options: ['where' => "((status)::text = 'running'::text)"])]
class JobRun
{
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 1])]
    private int $attempt = 1;

    #[ORM\Column(nullable: true)]
    private ?int $importId = null;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $importRunId = null;

    #[ORM\Column(nullable: true)]
    private ?int $hospitalId = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private int|string|null $peakMemoryBytes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $thresholdsExceeded = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorClass = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $metrics = [];

    /** @psalm-suppress PossiblyUnusedMethod Rows are written via DBAL; tests build runs directly. */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)]
        private string $id,
        #[ORM\Column(length: 48)]
        private string $job,
        #[ORM\Column(length: 16)]
        private string $triggeredBy,
        #[ORM\Column(length: 16)]
        private string $status,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startedAt,
    ) {
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getId(): string
    {
        return $this->id;
    }

    public function getJob(): string
    {
        return $this->job;
    }

    public function getTriggeredBy(): string
    {
        return $this->triggeredBy;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function getImportId(): ?int
    {
        return $this->importId;
    }

    public function getImportRunId(): ?string
    {
        return $this->importRunId;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getHospitalId(): ?int
    {
        return $this->hospitalId;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getPeakMemoryBytes(): ?int
    {
        return null === $this->peakMemoryBytes ? null : (int) $this->peakMemoryBytes;
    }

    /**
     * The comma list of exceeded thresholds, e.g. "slow,memory", as a list.
     *
     * @return list<string>
     */
    public function getThresholdsExceededList(): array
    {
        if (null === $this->thresholdsExceeded || '' === $this->thresholdsExceeded) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode(',', $this->thresholdsExceeded)), static fn (string $kind): bool => '' !== $kind));
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    /** @psalm-suppress PossiblyUnusedMethod Read by EasyAdmin. */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }
}
