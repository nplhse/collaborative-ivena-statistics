<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Entity;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\Statistics\Infrastructure\Repository\AnalysisViewUsageRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalysisViewUsageRepository::class)]
#[ORM\Table(name: 'analysis_view_usage')]
#[ORM\UniqueConstraint(name: 'uniq_usage_system_view', columns: ['user_id', 'source', 'system_view_key'])]
#[ORM\UniqueConstraint(name: 'uniq_usage_saved_view', columns: ['user_id', 'saved_view_id'])]
class AnalysisViewUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(enumType: AnalysisViewSource::class)]
    private AnalysisViewSource $source;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $systemViewKey = null;

    #[ORM\ManyToOne(targetEntity: SavedAnalysisView::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?SavedAnalysisView $savedView = null;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    #[ORM\Column]
    private int $useCount = 1;

    public function __construct(
        User $user,
        AnalysisViewSource $source,
        ?string $systemViewKey = null,
        ?SavedAnalysisView $savedView = null,
    ) {
        $this->user = $user;
        $this->source = $source;
        $this->systemViewKey = $systemViewKey;
        $this->savedView = $savedView;
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSource(): AnalysisViewSource
    {
        return $this->source;
    }

    public function getSystemViewKey(): ?string
    {
        return $this->systemViewKey;
    }

    public function getSavedView(): ?SavedAnalysisView
    {
        return $this->savedView;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getUseCount(): int
    {
        return $this->useCount;
    }

    public function recordUse(): void
    {
        ++$this->useCount;
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
