<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity;

use App\Import\Domain\Enum\ImportBatchRunItemStatus;
use App\Import\Infrastructure\Repository\ImportBatchRunItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportBatchRunItemRepository::class)]
#[ORM\Table(name: 'import_batch_run_item')]
#[ORM\Index(name: 'idx_import_batch_run_item_run_import', columns: ['run_id', 'import_id'])]
#[ORM\Index(name: 'idx_import_batch_run_item_run_status', columns: ['run_id', 'status'])]
class ImportBatchRunItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportBatchRun::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ImportBatchRun $run = null;

    #[ORM\Column]
    private int $importId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $importName = null;

    #[ORM\Column(enumType: ImportBatchRunItemStatus::class)]
    private ImportBatchRunItemStatus $status = ImportBatchRunItemStatus::Pending;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private int $attemptCount = 0;

    public function __construct(int $importId, ?string $importName = null)
    {
        $this->importId = $importId;
        $this->importName = $importName;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): ?ImportBatchRun
    {
        return $this->run;
    }

    public function setRun(?ImportBatchRun $run): self
    {
        $this->run = $run;

        return $this;
    }

    public function getImportId(): int
    {
        return $this->importId;
    }

    public function getImportName(): ?string
    {
        return $this->importName;
    }

    public function getStatus(): ImportBatchRunItemStatus
    {
        return $this->status;
    }

    public function setStatus(ImportBatchRunItemStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): self
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getAttemptCount(): int
    {
        return $this->attemptCount;
    }

    public function incrementAttemptCount(): self
    {
        ++$this->attemptCount;

        return $this;
    }

    public function markRunning(): self
    {
        $this->status = ImportBatchRunItemStatus::Running;
        $this->startedAt = new \DateTimeImmutable();
        $this->finishedAt = null;
        $this->incrementAttemptCount();

        return $this;
    }

    public function markQueued(): self
    {
        $this->status = ImportBatchRunItemStatus::Queued;
        $this->finishedAt = new \DateTimeImmutable();
        $this->errorMessage = null;

        return $this;
    }

    public function markDispatchFailed(string $message): self
    {
        $this->status = ImportBatchRunItemStatus::DispatchFailed;
        $this->finishedAt = new \DateTimeImmutable();
        $this->errorMessage = $message;

        return $this;
    }

    public function markInterrupted(): self
    {
        $this->status = ImportBatchRunItemStatus::Interrupted;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function markSkipped(string $message): self
    {
        $this->status = ImportBatchRunItemStatus::Skipped;
        $this->finishedAt = new \DateTimeImmutable();
        $this->errorMessage = $message;

        return $this;
    }
}
