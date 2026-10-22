<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity;

use App\Import\Domain\Enum\ImportRunReasonCode;
use App\Import\Domain\Enum\ImportRunStatus;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One deliberate processing request of an import: an upload, a console start or one requeue batch item.
 *
 * Terminal transitions are conditional updates (see ImportRunLifecycle), so this entity only
 * changes in memory while the run is being created or started.
 */
#[ORM\Entity(repositoryClass: ImportRunRepository::class)]
#[ORM\Table(name: 'import_run')]
#[ORM\Index(name: 'idx_import_run_status_finished', columns: ['status', 'finished_at'])]
class ImportRun
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Import::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Import $import;

    #[ORM\Column(length: 16, enumType: ImportRunTrigger::class)]
    private ImportRunTrigger $trigger;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $initiatedBy;

    #[ORM\OneToOne(targetEntity: ImportBatchRunItem::class, mappedBy: 'importRun')]
    private ?ImportBatchRunItem $batchRunItem = null;

    #[ORM\Column(length: 16, enumType: ImportRunStatus::class)]
    private ImportRunStatus $status = ImportRunStatus::Queued;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $rowsTotal = null;

    #[ORM\Column(nullable: true)]
    private ?int $rowsOk = null;

    #[ORM\Column(nullable: true)]
    private ?int $rowsRejected = null;

    #[ORM\Column(length: 64, nullable: true, enumType: ImportRunReasonCode::class)]
    private ?ImportRunReasonCode $reasonCode = null;

    public function __construct(Uuid $id, Import $import, ImportRunTrigger $trigger, ?User $initiatedBy = null, ?\DateTimeImmutable $createdAt = null)
    {
        $this->id = $id;
        $this->import = $import;
        $this->trigger = $trigger;
        $this->initiatedBy = $initiatedBy;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getImport(): Import
    {
        return $this->import;
    }

    public function getTrigger(): ImportRunTrigger
    {
        return $this->trigger;
    }

    public function getInitiatedBy(): ?User
    {
        return $this->initiatedBy;
    }

    public function getBatchRunItem(): ?ImportBatchRunItem
    {
        return $this->batchRunItem;
    }

    public function getStatus(): ImportRunStatus
    {
        return $this->status;
    }

    public function isFinished(): bool
    {
        return $this->status->isTerminal();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getRowsTotal(): ?int
    {
        return $this->rowsTotal;
    }

    public function getRowsOk(): ?int
    {
        return $this->rowsOk;
    }

    public function getRowsRejected(): ?int
    {
        return $this->rowsRejected;
    }

    public function getReasonCode(): ?ImportRunReasonCode
    {
        return $this->reasonCode;
    }

    /**
     * Moves a queued run to running. A run that is already running (Messenger retry) keeps its start time.
     */
    public function markRunning(\DateTimeImmutable $now): void
    {
        if (ImportRunStatus::Queued !== $this->status) {
            return;
        }

        $this->status = ImportRunStatus::Running;
        $this->startedAt = $now;
    }
}
