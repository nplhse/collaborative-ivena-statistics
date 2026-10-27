<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity;

use App\Import\Domain\Enum\ImportBatchRunStatus;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Clock\Clock;

#[ORM\Entity(repositoryClass: ImportBatchRunRepository::class)]
#[ORM\Table(name: 'import_batch_run')]
class ImportBatchRun
{
    /** `options.source` of a bulk reprocess started from the import list. Console batches have no source. */
    public const string SOURCE_UI = 'ui';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ImportBatchRunStatus::class)]
    private ImportBatchRunStatus $status = ImportBatchRunStatus::Running;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON)]
    private array $options = [];

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** All items were dispatched (set together with the status Finished). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $dispatchClosedAt = null;

    /** All dispatched items reached a terminal state; the summary was published (ADR 016). */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $processingFinishedAt = null;

    /** @var Collection<int, ImportBatchRunItem> */
    #[ORM\OneToMany(targetEntity: ImportBatchRunItem::class, mappedBy: 'run', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $now = Clock::get()->now();
        $this->options = $options;
        $this->startedAt = $now;
        $this->createdAt = $now;
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): ImportBatchRunStatus
    {
        return $this->status;
    }

    public function setStatus(ImportBatchRunStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    public function getInitiatedByUserId(): ?int
    {
        $initiator = $this->options['initiatedByUserId'] ?? null;

        return \is_int($initiator) ? $initiator : null;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDispatchClosedAt(): ?\DateTimeImmutable
    {
        return $this->dispatchClosedAt;
    }

    /**
     * Ends the dispatch phase: every item was handed to the queue or failed to be dispatched.
     */
    public function closeDispatch(\DateTimeImmutable $at): self
    {
        $this->status = ImportBatchRunStatus::Finished;
        $this->finishedAt = $at;
        $this->dispatchClosedAt = $at;

        return $this;
    }

    public function getProcessingFinishedAt(): ?\DateTimeImmutable
    {
        return $this->processingFinishedAt;
    }

    /** @return Collection<int, ImportBatchRunItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(ImportBatchRunItem $item): self
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setRun($this);
        }

        return $this;
    }

    public function getLastItem(): ?ImportBatchRunItem
    {
        if ($this->items->isEmpty()) {
            return null;
        }

        $last = null;
        foreach ($this->items as $item) {
            $last = $item;
        }

        return $last;
    }
}
