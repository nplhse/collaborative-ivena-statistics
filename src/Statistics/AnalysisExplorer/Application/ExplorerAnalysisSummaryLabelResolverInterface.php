<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\User\Domain\Entity\User;

interface ExplorerAnalysisSummaryLabelResolverInterface
{
    public function scopeLabel(StatisticsFilter $filter, ?User $user, ?string $locale = null): string;

    public function periodLabel(StatisticsFilter $filter, ?string $locale = null): string;
}
