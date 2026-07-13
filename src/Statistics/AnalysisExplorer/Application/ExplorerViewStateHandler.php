<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerMetadataCommitOutcome
{
    public function __construct(
        public bool $success,
        public ?string $savedViewTitle = null,
        public ?string $savedViewDescription = null,
        public bool $metadataManuallyEdited = false,
        public ?string $configWarning = null,
    ) {
    }
}

final readonly class ExplorerUnsavedChangeState
{
    public function __construct(
        public bool $hasUnsavedChanges,
        public bool $showSaveAs,
    ) {
    }
}

final readonly class ExplorerViewStateHandler
{
    public function __construct(
        private ExplorerDescriptionFactory $descriptionFactory,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, mixed> $baselineConfigState
     * @param array<string, mixed> $appliedConfigState
     */
    public function unsavedChangeState(
        array $baselineConfigState,
        array $appliedConfigState,
        bool $canSave,
        bool $canSaveAs,
        ?string $savedViewTitle,
        ?string $baselineViewTitle,
        ?string $savedViewDescription,
        ?string $baselineViewDescription,
    ): ExplorerUnsavedChangeState {
        $configDirty = $this->configStatesDiffer($baselineConfigState, $appliedConfigState);
        $metadataDirty = $canSave && $this->metadataDiffersFromBaseline(
            $savedViewTitle,
            $baselineViewTitle,
            $savedViewDescription,
            $baselineViewDescription,
        );

        return new ExplorerUnsavedChangeState(
            hasUnsavedChanges: $configDirty || $metadataDirty,
            showSaveAs: $canSaveAs && $configDirty,
        );
    }

    /**
     * @return array{title: ?string, description: ?string}
     */
    public function initializeMetadataBaselines(
        ?AnalysisViewConfig $config,
        ?string $savedViewTitle,
        ?string $savedViewDescription,
    ): array {
        if (null !== $savedViewTitle && '' !== trim($savedViewTitle)) {
            return [
                'title' => $savedViewTitle,
                'description' => $savedViewDescription ?? '',
            ];
        }

        if ($config instanceof AnalysisViewConfig) {
            return [
                'title' => $config->title,
                'description' => $this->descriptionFactory->descriptionForConfig($config),
            ];
        }

        return [
            'title' => null,
            'description' => null,
        ];
    }

    /**
     * @return array{title: string, description: string}
     */
    public function populateEditViewMetadataFields(
        AnalysisViewConfig $config,
        bool $metadataManuallyEdited,
        ?string $savedViewTitle,
        ?string $savedViewDescription,
    ): array {
        if ($metadataManuallyEdited) {
            return [
                'title' => $savedViewTitle ?? '',
                'description' => $savedViewDescription ?? '',
            ];
        }

        return [
            'title' => $savedViewTitle ?? $config->title,
            'description' => $savedViewDescription ?? $this->descriptionFactory->descriptionForConfig($config),
        ];
    }

    public function commitMetadataFromApply(
        AnalysisViewConfig $normalizedConfig,
        bool $metadataManuallyEdited,
        bool $metadataEditedInCurrentDrawerSession,
        string $editViewTitle,
        string $editViewDescription,
    ): ExplorerMetadataCommitOutcome {
        if ($metadataEditedInCurrentDrawerSession) {
            $metadataManuallyEdited = true;
        }

        if ($metadataManuallyEdited) {
            $title = trim($editViewTitle);
            if ('' === $title) {
                return new ExplorerMetadataCommitOutcome(
                    success: false,
                    configWarning: $this->translator->trans('stats.analysis_explorer.save_as.title_required', [], 'statistics'),
                );
            }

            return new ExplorerMetadataCommitOutcome(
                success: true,
                savedViewTitle: $title,
                savedViewDescription: trim($editViewDescription),
                metadataManuallyEdited: true,
            );
        }

        return new ExplorerMetadataCommitOutcome(
            success: true,
            savedViewTitle: $normalizedConfig->title,
            savedViewDescription: $this->descriptionFactory->descriptionForConfig($normalizedConfig),
            metadataManuallyEdited: false,
        );
    }

    public function metadataEditedInCurrentDrawerSession(
        string $editViewTitle,
        string $editViewDescription,
        string $editViewTitleAtOpen,
        string $editViewDescriptionAtOpen,
    ): bool {
        return trim($editViewTitle) !== trim($editViewTitleAtOpen)
            || trim($editViewDescription) !== trim($editViewDescriptionAtOpen);
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function configStatesDiffer(array $left, array $right): bool
    {
        return json_encode($left, \JSON_THROW_ON_ERROR) !== json_encode($right, \JSON_THROW_ON_ERROR);
    }

    private function metadataDiffersFromBaseline(
        ?string $savedViewTitle,
        ?string $baselineViewTitle,
        ?string $savedViewDescription,
        ?string $baselineViewDescription,
    ): bool {
        return ($savedViewTitle ?? '') !== ($baselineViewTitle ?? '')
            || ($savedViewDescription ?? '') !== ($baselineViewDescription ?? '');
    }
}
