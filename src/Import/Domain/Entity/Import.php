<?php

namespace App\Import\Domain\Entity;

use App\Allocation\Domain\Entity\Hospital;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Domain\Traits\Blamable;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportRepository::class)]
#[ORM\HasLifecycleCallbacks()]
class Import
{
    use Blamable;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Hospital $hospital = null;

    #[ORM\Column(enumType: ImportStatus::class)]
    private ?ImportStatus $status = null;

    #[ORM\Column(enumType: ImportType::class)]
    private ?ImportType $type = null;

    #[ORM\Column(length: 255)]
    private ?string $filePath = null;

    #[ORM\Column(length: 255)]
    private ?string $fileExtension = null;

    #[ORM\Column(length: 255)]
    private ?string $fileMimeType = null;

    #[ORM\Column]
    private ?int $fileSize = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $fileChecksum = null;

    #[ORM\Column]
    private ?int $rowCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $rowsPassed = null;

    #[ORM\Column(nullable: true)]
    private ?int $rowsRejected = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rejectFilePath = null;

    #[ORM\Column]
    private ?int $runCount = null;

    #[ORM\Column]
    private ?int $runTime = null;

    #[ORM\Column()]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @psalm-suppress PropertyNotSetInConstructor */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    protected ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    protected ?User $updatedBy = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getHospital(): ?Hospital
    {
        return $this->hospital;
    }

    public function setHospital(?Hospital $hospital): static
    {
        $this->hospital = $hospital;

        return $this;
    }

    public function getStatus(): ?ImportStatus
    {
        return $this->status;
    }

    public function setStatus(ImportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getType(): ?ImportType
    {
        return $this->type;
    }

    public function setType(ImportType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    public function setFilePath(string $filePath): static
    {
        $this->filePath = $filePath;

        return $this;
    }

    public function getFileExtension(): ?string
    {
        return $this->fileExtension;
    }

    public function setFileExtension(string $fileExtension): static
    {
        $this->fileExtension = $fileExtension;

        return $this;
    }

    public function getFileMimeType(): ?string
    {
        return $this->fileMimeType;
    }

    public function setFileMimeType(string $fileMimeType): static
    {
        $this->fileMimeType = $fileMimeType;

        return $this;
    }

    public function getFileSize(): ?int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): static
    {
        $this->fileSize = $fileSize;

        return $this;
    }

    public function getFileChecksum(): ?string
    {
        return $this->fileChecksum;
    }

    public function setFileChecksum(?string $fileChecksum): static
    {
        $this->fileChecksum = $fileChecksum;

        return $this;
    }

    public function getRowCount(): ?int
    {
        return $this->rowCount;
    }

    public function setRowCount(int $rowCount): static
    {
        $this->rowCount = $rowCount;

        return $this;
    }

    public function getRowsPassed(): ?int
    {
        return $this->rowsPassed;
    }

    public function setRowsPassed(?int $rowsPassed): static
    {
        $this->rowsPassed = $rowsPassed;

        return $this;
    }

    public function getRowsRejected(): ?int
    {
        return $this->rowsRejected;
    }

    public function setRowsRejected(?int $rowsRejected): static
    {
        $this->rowsRejected = $rowsRejected;

        return $this;
    }

    public function getRejectFilePath(): ?string
    {
        return $this->rejectFilePath;
    }

    public function setRejectFilePath(?string $rejectFilePath): static
    {
        $this->rejectFilePath = $rejectFilePath;

        return $this;
    }

    public function getRunCount(): ?int
    {
        return $this->runCount;
    }

    public function setRunCount(int $runCount): static
    {
        $this->runCount = $runCount;

        return $this;
    }

    public function getRunTime(): ?int
    {
        return $this->runTime;
    }

    public function setRunTime(int $runTime): static
    {
        $this->runTime = $runTime;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->setUpdatedAt(new \DateTimeImmutable('now'));
    }

    public function __toString(): string
    {
        return $this->name ?? 'No name';
    }
}
