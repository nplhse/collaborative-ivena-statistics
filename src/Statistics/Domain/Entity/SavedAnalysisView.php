<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Entity;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewConfig;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewVisibility;
use App\Statistics\Infrastructure\Repository\SavedAnalysisViewRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedAnalysisViewRepository::class)]
#[ORM\Table(name: 'saved_analysis_view')]
class SavedAnalysisView
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 180)]
    private string $title;

    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: \Doctrine\DBAL\Types\Types::JSON)]
    private array $configJson = [];

    #[ORM\Column(enumType: AnalysisViewVisibility::class)]
    private AnalysisViewVisibility $visibility;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $sourceSystemViewKey = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    public function __construct(
        User $owner,
        string $title,
        AnalysisViewConfig $config,
        ?string $description = null,
        ?string $sourceSystemViewKey = null,
        AnalysisViewVisibility $visibility = AnalysisViewVisibility::Private,
    ) {
        $this->owner = $owner;
        $this->title = $title;
        $this->description = $description;
        $this->configJson = $config->toArray();
        $this->visibility = $visibility;
        $this->sourceSystemViewKey = $sourceSystemViewKey;
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function getConfig(): AnalysisViewConfig
    {
        return AnalysisViewConfig::fromArray($this->configJson);
    }

    public function setConfig(AnalysisViewConfig $config): void
    {
        $this->configJson = $config->toArray();
        $this->touch();
    }

    public function getVisibility(): AnalysisViewVisibility
    {
        return $this->visibility;
    }

    public function getSourceSystemViewKey(): ?string
    {
        return $this->sourceSystemViewKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function touchLastUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
