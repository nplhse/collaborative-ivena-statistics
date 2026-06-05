<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisViewCategory;

final readonly class AnalysisViewLibraryService
{
    public function __construct(
        private AnalysisViewSearchService $searchService,
        private AnalysisViewRecommendationServiceInterface $recommendationService,
    ) {
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function listViews(
        ?string $query = null,
        ?AnalysisViewCategory $category = null,
        ?string $tag = null,
    ): array {
        return $this->searchService->search($query, $category, $tag);
    }

    /**
     * @return list<AnalysisViewDefinition>
     */
    public function recommendedViews(?\App\User\Domain\Entity\User $user, int $limit = 12): array
    {
        return $this->recommendationService->recommendForUser($user, $limit);
    }

    /**
     * @return list<AnalysisViewCategory>
     */
    public function categories(): array
    {
        return AnalysisViewCategory::cases();
    }
}
