<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\AnalysisExplorer\Domain\Enum\ExplorerQueryKeys;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hospital master-data analyses need the full hospital population by default,
 * not the participant's "my hospitals" scope.
 */
final readonly class HospitalStatisticsScopeDefaultPolicy
{
    public function shouldDefaultToPublicScope(Request $request): bool
    {
        if (AnalysisDataSource::Hospitals->value === $request->query->getString(ExplorerQueryKeys::DATA_SOURCE)) {
            return true;
        }

        $route = (string) $request->attributes->get('_route', '');
        if ('app_stats_hospital_population' === $route) {
            return true;
        }

        return 'app_stats_analysis_explorer' === $route
            && AnalysisDataSource::Hospitals->value === $request->query->getString(ExplorerQueryKeys::DATA_SOURCE);
    }
}
