<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\Exception\SavedExplorerViewForbiddenException;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\Statistics\Infrastructure\Repository\SavedExplorerViewRepository;
use App\User\Domain\Entity\User;

final readonly class SavedExplorerViewService
{
    public const string USER_VIEW_CATEGORY = 'My views';

    public function __construct(
        private SavedExplorerViewRepository $repository,
        private ExplorerConfigMapper $configMapper,
        private AnalysisViewConfigNormalizer $configNormalizer,
        private AnalysisViewConfigValidator $configValidator,
    ) {
    }

    /**
     * @param array<string, mixed> $appliedState
     */
    public function create(
        User $user,
        string $title,
        array $appliedState,
        ?string $description = null,
        ?string $category = null,
    ): SavedExplorerView {
        $configJson = $this->normalizeStateToConfigJson($appliedState, $user);
        $configJson['title'] = $title;

        $view = new SavedExplorerView(
            slug: null,
            title: $title,
            category: $category ?? self::USER_VIEW_CATEGORY,
            configJson: $configJson,
            description: $description,
            isSystem: false,
        );

        $this->repository->save($view);

        return $view;
    }

    /**
     * @param array<string, mixed> $appliedState
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function update(
        SavedExplorerView $view,
        User $user,
        string $title,
        array $appliedState,
        ?string $description = null,
    ): SavedExplorerView {
        if (!$view->isEditableBy($user)) {
            throw new SavedExplorerViewForbiddenException('User cannot update this explorer view.');
        }

        $configJson = $this->normalizeStateToConfigJson($appliedState, $user);
        $configJson['title'] = $title;

        $view->update(
            title: $title,
            category: $view->getCategory(),
            configJson: $configJson,
            description: $description,
        );
        $this->repository->save($view);

        return $view;
    }

    /**
     * @param array<string, mixed> $appliedState
     *
     * @return array<string, mixed>
     */
    private function normalizeStateToConfigJson(array $appliedState, User $user): array
    {
        try {
            $config = $this->configMapper->viewConfigFromState($appliedState, $user);
            $normalized = $this->configNormalizer->normalize($config);
            $this->configValidator->validate($normalized);

            return $this->configMapper->toStateArray($normalized);
        } catch (InvalidExplorerConfigException $exception) {
            throw $exception;
        }
    }
}
