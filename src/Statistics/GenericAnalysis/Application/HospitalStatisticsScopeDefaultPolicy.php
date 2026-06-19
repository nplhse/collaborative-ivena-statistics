<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application;

use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use Symfony\Component\HttpFoundation\Request;

/**
 * Hospital master-data analyses need the full hospital population by default,
 * not the participant's "my hospitals" scope.
 */
final readonly class HospitalStatisticsScopeDefaultPolicy
{
    public function __construct(
        private AnalysisViewRegistry $viewRegistry,
    ) {
    }

    public function shouldDefaultToPublicScope(Request $request): bool
    {
        if (AnalysisDataSource::Hospitals->value === $request->query->getString(GenericAnalysisQueryKeys::DATA_SOURCE)) {
            return true;
        }

        $route = (string) $request->attributes->get('_route', '');
        if ('app_stats_hospital_population' === $route) {
            return true;
        }

        if ('app_stats_analytics_view' !== $route) {
            return false;
        }

        $viewKey = $request->attributes->get('viewKey');
        if (!\is_string($viewKey) || '' === $viewKey || !$this->viewRegistry->has($viewKey)) {
            return false;
        }

        return AnalysisDataSource::Hospitals === $this->viewRegistry->get($viewKey)->dataSource;
    }
}
