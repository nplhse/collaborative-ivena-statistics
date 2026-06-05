<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\Domain\Entity\FavoriteAnalysisView;
use App\Statistics\Domain\Entity\SavedAnalysisView;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewSource;
use App\Statistics\Infrastructure\Repository\FavoriteAnalysisViewRepository;
use App\User\Domain\Entity\User;

final readonly class FavoriteAnalysisViewService
{
    public function __construct(
        private FavoriteAnalysisViewRepository $repository,
    ) {
    }

    public function toggleSystemView(User $user, string $systemViewKey): bool
    {
        $existing = $this->repository->findSystemFavorite($user, $systemViewKey);
        if ($existing instanceof FavoriteAnalysisView) {
            $this->repository->remove($existing);

            return false;
        }

        $favorite = new FavoriteAnalysisView(
            user: $user,
            source: AnalysisViewSource::System,
            systemViewKey: $systemViewKey,
        );
        $favorite->setSortOrder($this->nextSortOrder($user));
        $this->repository->save($favorite);

        return true;
    }

    public function toggleSavedView(User $user, SavedAnalysisView $savedView): bool
    {
        $existing = $this->repository->findSavedFavorite($user, $savedView);
        if ($existing instanceof FavoriteAnalysisView) {
            $this->repository->remove($existing);

            return false;
        }

        $favorite = new FavoriteAnalysisView(
            user: $user,
            source: AnalysisViewSource::Saved,
            savedView: $savedView,
        );
        $favorite->setSortOrder($this->nextSortOrder($user));
        $this->repository->save($favorite);

        return true;
    }

    public function isSystemFavorite(User $user, string $systemViewKey): bool
    {
        return $this->repository->findSystemFavorite($user, $systemViewKey) instanceof FavoriteAnalysisView;
    }

    public function isSavedFavorite(User $user, SavedAnalysisView $savedView): bool
    {
        return $this->repository->findSavedFavorite($user, $savedView) instanceof FavoriteAnalysisView;
    }

    /**
     * @return list<FavoriteAnalysisView>
     */
    public function listForUser(User $user): array
    {
        return $this->repository->findForUserOrdered($user);
    }

    private function nextSortOrder(User $user): int
    {
        $favorites = $this->repository->findForUserOrdered($user);
        if ([] === $favorites) {
            return 0;
        }

        return $favorites[\count($favorites) - 1]->getSortOrder() + 1;
    }
}
