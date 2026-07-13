<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\Domain\Exception\SavedExplorerViewForbiddenException;
use App\Statistics\Domain\Entity\SavedExplorerView;
use App\User\Domain\Entity\User;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerSaveAsOutcome
{
    public function __construct(
        public ?RedirectResponse $redirect = null,
        public ?string $configWarning = null,
        public bool $metadataManuallyEditedReset = false,
    ) {
    }
}

final readonly class ExplorerSaveOutcome
{
    public function __construct(
        public bool $saved,
        public ?string $savedViewTitle = null,
        public ?string $savedViewDescription = null,
        public ?string $configWarning = null,
    ) {
    }
}

final readonly class ExplorerSavedViewHandler
{
    public function __construct(
        private SavedExplorerViewService $savedViewService,
        private ExplorerDescriptionFactory $descriptionFactory,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array{title: string, description: string}
     */
    public function prepareSaveAsDefaults(
        ?AnalysisViewConfig $config,
        bool $metadataManuallyEdited,
        ?string $savedViewTitle,
        ?string $savedViewDescription,
    ): array {
        if (!$config instanceof AnalysisViewConfig) {
            return [
                'title' => '',
                'description' => '',
            ];
        }

        if ($metadataManuallyEdited) {
            return [
                'title' => $savedViewTitle ?? '',
                'description' => $savedViewDescription ?? '',
            ];
        }

        return [
            'title' => $config->title,
            'description' => $this->descriptionFactory->descriptionForConfig($config),
        ];
    }

    /**
     * @param array<string, mixed> $appliedConfigState
     */
    public function saveAs(
        User $user,
        string $saveAsTitle,
        string $saveAsDescription,
        array $appliedConfigState,
    ): ExplorerSaveAsOutcome {
        $title = trim($saveAsTitle);
        if ('' === $title) {
            return new ExplorerSaveAsOutcome(
                configWarning: $this->translator->trans('stats.analysis_explorer.save_as.title_required', [], 'statistics'),
            );
        }

        try {
            $description = '' !== trim($saveAsDescription) ? trim($saveAsDescription) : null;
            $view = $this->savedViewService->create(
                $user,
                $title,
                $appliedConfigState,
                $description,
            );
        } catch (InvalidExplorerConfigException $exception) {
            return new ExplorerSaveAsOutcome(
                configWarning: $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics'),
            );
        }

        $viewId = $view->getId();
        if (null === $viewId) {
            return new ExplorerSaveAsOutcome();
        }

        return new ExplorerSaveAsOutcome(
            redirect: new RedirectResponse($this->urlGenerator->generate('app_stats_analysis_explorer_view', [
                'view' => (string) $viewId,
            ])),
            metadataManuallyEditedReset: true,
        );
    }

    /**
     * @param array<string, mixed> $appliedConfigState
     */
    public function save(
        SavedExplorerView $view,
        User $user,
        ?string $savedViewTitle,
        ?string $savedViewDescription,
        array $appliedConfigState,
    ): ExplorerSaveOutcome {
        $title = $savedViewTitle ?? $view->getTitle();
        $description = null !== $savedViewDescription && '' !== trim($savedViewDescription)
            ? trim($savedViewDescription)
            : $view->getDescription();

        try {
            $this->savedViewService->update(
                $view,
                $user,
                $title,
                $appliedConfigState,
                $description,
            );
        } catch (SavedExplorerViewForbiddenException) {
            return new ExplorerSaveOutcome(
                saved: false,
                configWarning: $this->translator->trans('stats.analysis_explorer.save.forbidden', [], 'statistics'),
            );
        } catch (InvalidExplorerConfigException $exception) {
            return new ExplorerSaveOutcome(
                saved: false,
                configWarning: $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics'),
            );
        }

        return new ExplorerSaveOutcome(
            saved: true,
            savedViewTitle: $view->getTitle(),
            savedViewDescription: $view->getDescription() ?? '',
            configWarning: $this->translator->trans('stats.analysis_explorer.saved', [], 'statistics'),
        );
    }
}
