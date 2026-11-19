<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class ExplorerViewAuthorPresenter
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    private const array NONE = ['name' => null, 'displayName' => null, 'userId' => null, 'url' => null];

    /**
     * name is the username; displayName and userId feed user_display_name() and UserAvatar in the
     * template, which apply the visibility rule (#1240).
     *
     * @return array{name: string|null, displayName: string|null, userId: int|null, url: string|null}
     */
    public function present(?SavedExplorerView $view): array
    {
        if (!$view instanceof SavedExplorerView || $view->isSystem()) {
            return self::NONE;
        }

        $creator = $view->getCreatedBy();
        if (!$creator instanceof User) {
            return self::NONE;
        }

        $name = $creator->getUsername();
        if (null === $name || '' === $name) {
            return self::NONE;
        }

        return [
            'name' => $name,
            'displayName' => $creator->getDisplayName(),
            'userId' => $creator->getId(),
            'url' => $this->urlGenerator->generate('app_explore_user_show', ['username' => $name]),
        ];
    }
}
