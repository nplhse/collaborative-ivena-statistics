<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\DTO\AnalysisViewDefinition;
use App\User\Domain\Entity\User;

interface AnalysisViewRecommendationServiceInterface
{
    /**
     * @return list<AnalysisViewDefinition>
     */
    public function recommendForUser(?User $user, int $limit = 6): array;
}
