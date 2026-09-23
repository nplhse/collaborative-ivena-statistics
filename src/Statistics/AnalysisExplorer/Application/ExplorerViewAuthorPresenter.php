<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Entity\User;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final readonly class ExplorerViewAuthorPresenter
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array{name: string|null, url: string|null}
     */
    public function present(?SavedExplorerView $view): array
    {
        if (!$view instanceof SavedExplorerView || $view->isSystem()) {
            return ['name' => null, 'url' => null];
        }

        $creator = $view->getCreatedBy();
        if (!$creator instanceof User) {
            return ['name' => null, 'url' => null];
        }

        $name = $creator->getUsername();
        if (null === $name || '' === $name) {
            return ['name' => null, 'url' => null];
        }

        $publicId = $creator->getPublicId();

        return [
            'name' => $name,
            'url' => $publicId instanceof Uuid
                ? $this->urlGenerator->generate('app_explore_user_show', ['publicId' => $publicId->toRfc4122()])
                : null,
        ];
    }
}
