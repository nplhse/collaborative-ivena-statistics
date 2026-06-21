<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Domain\Entity\SavedExplorerViewFavorite;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewFavoriteRepository;
use App\User\Domain\Entity\User;

final readonly class SavedExplorerViewFavoriteService
{
    public function __construct(
        private SavedExplorerViewFavoriteRepository $repository,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function toggle(User $user, SavedExplorerView $view): bool
    {
        $existing = $this->repository->findForUserAndView($user, $view);
        if ($existing instanceof SavedExplorerViewFavorite) {
            $this->repository->remove($existing);

            return false;
        }

        $this->repository->save(new SavedExplorerViewFavorite($user, $view));

        return true;
    }

    public function isFavorite(User $user, SavedExplorerView $view): bool
    {
        return $this->repository->findForUserAndView($user, $view) instanceof SavedExplorerViewFavorite;
    }

    /**
     * @return list<SavedExplorerView>
     */
    public function listViewsForUser(User $user): array
    {
        return $this->repository->findViewsForUserOrdered($user);
    }
}
