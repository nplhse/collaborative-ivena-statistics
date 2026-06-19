<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\HospitalStatisticsScopeDefaultPolicy;
use App\Statistics\GenericAnalysis\Domain\Enum\AnalysisDataSource;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class HospitalStatisticsScopeDefaultPolicyTest extends TestCase
{
    private HospitalStatisticsScopeDefaultPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new HospitalStatisticsScopeDefaultPolicy(new AnalysisViewRegistry());
    }

    public function testDefaultsToPublicForHospitalDataSourceQuery(): void
    {
        $request = Request::create('/statistics/analytics/builder', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::DATA_SOURCE => AnalysisDataSource::Hospitals->value,
        ]);

        self::assertTrue($this->policy->shouldDefaultToPublicScope($request));
    }

    public function testDefaultsToPublicForHospitalSystemViewRoute(): void
    {
        $request = Request::create('/statistics/analytics/view/hospitals_by_tier_compare');
        $request->attributes->set('_route', 'app_stats_analytics_view');
        $request->attributes->set('viewKey', 'hospitals_by_tier_compare');

        self::assertTrue($this->policy->shouldDefaultToPublicScope($request));
    }

    public function testDoesNotDefaultToPublicForAllocationSystemViewRoute(): void
    {
        $request = Request::create('/statistics/analytics/view/allocations_by_month');
        $request->attributes->set('_route', 'app_stats_analytics_view');
        $request->attributes->set('viewKey', 'allocations_by_month');

        self::assertFalse($this->policy->shouldDefaultToPublicScope($request));
    }

    public function testDefaultsToPublicForHospitalPopulationDashboard(): void
    {
        $request = Request::create('/statistics/hospital-population');
        $request->attributes->set('_route', 'app_stats_hospital_population');

        self::assertTrue($this->policy->shouldDefaultToPublicScope($request));
    }
}
