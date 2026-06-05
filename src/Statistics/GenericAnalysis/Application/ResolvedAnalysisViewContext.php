<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Application\DTO\ResolvedGenericAnalysisConfig;
use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;

final readonly class ResolvedAnalysisViewContext
{
    public function __construct(
        public AnalysisViewDefinition $view,
        public ResolvedGenericAnalysisConfig $config,
        public string $sourceKey,
        public bool $isSaved,
        public ?int $savedViewId = null,
    ) {
    }

    public function routeViewKey(): string
    {
        if ($this->isSaved && null !== $this->savedViewId) {
            return 'saved_'.$this->savedViewId;
        }

        return $this->view->key;
    }
}
