<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\Exception\InvalidExplorerConfigException;
use App\Statistics\AnalysisExplorer\UI\Form\Data\ExplorerEditFormData;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerEditApplyOutcome
{
    public function __construct(
        public ?AnalysisViewConfig $normalizedConfig = null,
        public ?string $configWarning = null,
        public bool $applied = false,
    ) {
    }
}

final readonly class ExplorerEditApplyHandler
{
    public function __construct(
        private ExplorerConfigMapper $configMapper,
        private AnalysisViewConfigValidator $configValidator,
        private AnalysisViewConfigNormalizer $configNormalizer,
        private TranslatorInterface $translator,
    ) {
    }

    public function apply(
        AnalysisViewConfig $currentConfig,
        ExplorerEditFormData $formData,
        ?User $user,
    ): ExplorerEditApplyOutcome {
        $newConfig = $this->configMapper->toViewConfig($formData, $currentConfig, $user);
        $normalizedConfig = $this->configNormalizer->normalize($newConfig);
        $configWarning = $this->normalizationWarning($newConfig, $normalizedConfig);

        try {
            $this->configValidator->validate($normalizedConfig);
        } catch (InvalidExplorerConfigException $exception) {
            return new ExplorerEditApplyOutcome(
                configWarning: $this->translator->trans($exception->translationKey, $exception->parameters, 'statistics'),
            );
        }

        return new ExplorerEditApplyOutcome(
            normalizedConfig: $normalizedConfig,
            configWarning: $configWarning,
            applied: true,
        );
    }

    private function normalizationWarning(AnalysisViewConfig $original, AnalysisViewConfig $normalized): ?string
    {
        if ([] === $this->configNormalizer->diffWarnings($original, $normalized)) {
            return null;
        }

        return $this->translator->trans('stats.analysis_explorer.config_normalized', [], 'statistics');
    }
}
