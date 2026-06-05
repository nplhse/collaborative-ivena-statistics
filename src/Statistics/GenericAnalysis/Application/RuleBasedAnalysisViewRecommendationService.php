<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\User\Domain\Entity\User;

/**
 * Stub recommendation engine: featured views first, then usage-based rules (TODO).
 */
final readonly class RuleBasedAnalysisViewRecommendationService implements AnalysisViewRecommendationServiceInterface
{
    public function __construct(
        private AnalysisViewRegistry $viewRegistry,
        private ?AnalysisViewRecentService $recentService = null,
    ) {
    }

    #[\Override]
    public function recommendForUser(?User $user, int $limit = 6): array
    {
        $featured = $this->viewRegistry->featured();
        if ([] !== $featured) {
            return \array_slice($featured, 0, $limit);
        }

        if ($user instanceof User && $this->recentService instanceof AnalysisViewRecentService) {
            $frequent = $this->recentService->mostFrequent($user, $limit);
            if ([] !== $frequent) {
                return $frequent;
            }
        }

        return \array_slice($this->viewRegistry->all(), 0, $limit);
    }
}
