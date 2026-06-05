<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;

final readonly class AnalysisViewSearchService
{
    public function __construct(
        private AnalysisViewRegistry $viewRegistry,
    ) {
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function search(
        ?string $query = null,
        ?AnalysisViewCategory $category = null,
        ?string $tag = null,
    ): array {
        $views = $this->viewRegistry->all();

        if ($category instanceof AnalysisViewCategory) {
            $views = array_values(array_filter(
                $views,
                static fn (AnalysisViewDefinition $view): bool => $view->category === $category,
            ));
        }

        if (null !== $tag && '' !== trim($tag)) {
            $needle = strtolower(trim($tag));
            $views = array_values(array_filter(
                $views,
                static fn (AnalysisViewDefinition $view): bool => \in_array($needle, array_map(strtolower(...), $view->tags), true),
            ));
        }

        if (null === $query || '' === trim($query)) {
            return $views;
        }

        $needle = strtolower(trim($query));

        return array_values(array_filter(
            $views,
            fn (AnalysisViewDefinition $view): bool => $this->matchesNeedle($view, $needle),
        ));
    }

    private function matchesNeedle(AnalysisViewDefinition $view, string $needle): bool
    {
        if (str_contains(strtolower($view->title), $needle)) {
            return true;
        }

        if (str_contains(strtolower($view->description), $needle)) {
            return true;
        }

        return array_any($view->tags, fn (string $tag): bool => str_contains(strtolower($tag), $needle));
    }
}
