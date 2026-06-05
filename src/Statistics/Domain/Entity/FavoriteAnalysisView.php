<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Entity;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\Statistics\Infrastructure\Repository\FavoriteAnalysisViewRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FavoriteAnalysisViewRepository::class)]
#[ORM\Table(name: 'favorite_analysis_view')]
#[ORM\UniqueConstraint(name: 'uniq_favorite_system_view', columns: ['user_id', 'source', 'system_view_key'])]
#[ORM\UniqueConstraint(name: 'uniq_favorite_saved_view', columns: ['user_id', 'saved_view_id'])]
class FavoriteAnalysisView
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
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

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
        $this->createdAt = new \DateTimeImmutable();
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
