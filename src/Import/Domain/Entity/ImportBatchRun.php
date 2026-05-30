<?php

declare(strict_types=1);

namespace App\Import\Domain\Entity;

use App\Import\Domain\Enum\ImportBatchRunStatus;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportBatchRunRepository::class)]
#[ORM\Table(name: 'import_batch_run')]
class ImportBatchRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(enumType: ImportBatchRunStatus::class)]
    private ImportBatchRunStatus $status = ImportBatchRunStatus::Running;

    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: 'json')]
    private array $options = [];

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, ImportBatchRunItem> */
    #[ORM\OneToMany(targetEntity: ImportBatchRunItem::class, mappedBy: 'run', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $items;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $now = new \DateTimeImmutable();
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
