<?php

declare(strict_types=1);

namespace App\Statistics\Domain\Entity;

use App\Statistics\Infrastructure\Repository\SavedExplorerViewFavoriteRepository;
use App\User\Domain\Entity\User;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedExplorerViewFavoriteRepository::class)]
#[ORM\Table(name: 'saved_explorer_view_favorite')]
#[ORM\UniqueConstraint(name: 'uniq_saved_explorer_view_favorite', columns: ['user_id', 'saved_explorer_view_id'])]
class SavedExplorerViewFavorite
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: SavedExplorerView::class)]
    #[ORM\JoinColumn(name: 'saved_explorer_view_id', nullable: false, onDelete: 'CASCADE')]
    private SavedExplorerView $savedView;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, SavedExplorerView $savedView)
    {
        $this->user = $user;
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

    public function getSavedView(): SavedExplorerView
    {
        return $this->savedView;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
