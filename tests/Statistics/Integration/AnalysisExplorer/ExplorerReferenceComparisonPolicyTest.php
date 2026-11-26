<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\AnalysisExplorer;

use App\Statistics\AnalysisExplorer\Application\AnalysisViewConfigNormalizer;
use App\Statistics\AnalysisExplorer\Application\Comparison\ExplorerReferenceComparisonPolicy;
use App\Statistics\AnalysisExplorer\Application\Comparison\ScopeHospitalIdsReaderInterface;
use App\Statistics\AnalysisExplorer\Application\DefaultAnalysisViewFactory;
use App\Statistics\AnalysisExplorer\Domain\AnalysisViewConfig;
use App\Statistics\AnalysisExplorer\Domain\DTO\AnalysisAxisRef;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDataSourceKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionKey;
use App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisMetricKey;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\Application\StatisticsContextFactory;
use App\Statistics\Application\StatisticsScopeResolver;
use App\Statistics\GenericAnalysis\Application\Contract\GenericAnalysisEntityLabelResolverInterface;
use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Which reference comparison is effective for a viewer (#1346). Scope membership and Benchmarking grants are stubbed,
 * so every rule is checked with explicit hospital ids: hospitals in scope minus references must stay ≥ 3 for every
 * scope kind, references must be distinct, inside the scope and carry Benchmarking, at most 5.
 */
final class ExplorerReferenceComparisonPolicyTest extends KernelTestCase
{
    public function testAReferenceWithThreeOtherHospitalsIsEffective(): void
    {
        $decision = $this->policy(scope: [1, 2, 3, 4], benchmarking: [1])->effective($this->config([1]), new User());

        self::assertFalse($decision->dropped);
        self::assertSame([1], $decision->config->referenceHospitalIds);
        self::assertTrue($decision->config->showsPercentOfSeries());
    }

    public function testTheMinimumAppliesToTheWholePlatformToo(): void
    {
        // Scope "all" with 3 hospitals: one reference leaves only 2 others.
        $decision = $this->policy(scope: [1, 2, 3], benchmarking: [1])->effective($this->config([1]), new User());

        self::assertTrue($decision->dropped);
    }

    public function testTheMinimumAppliesToMyHospitals(): void
    {
        $config = $this->config([1], StatisticsFilterScope::MyHospitals);

        self::assertFalse($this->policy(scope: [1, 2, 3, 4], benchmarking: [1])->effective($config, new User())->dropped);
        self::assertTrue($this->policy(scope: [1, 2, 3], benchmarking: [1])->effective($config, new User())->dropped);
    }

    public function testTwoReferencesNeedThreeOthers(): void
    {
        $policy = $this->policy(scope: [1, 2, 3, 4], benchmarking: [1, 2]);

        self::assertTrue($policy->effective($this->config([1, 2]), new User())->dropped);
        self::assertFalse($this->policy(scope: [1, 2, 3, 4, 5], benchmarking: [1, 2])->effective($this->config([1, 2]), new User())->dropped);
    }

    public function testReferencesAreCountedAsDistinctIds(): void
    {
        $decision = $this->policy(scope: [1, 2, 3, 4], benchmarking: [1])->effective($this->config([1, 1]), new User());

        self::assertFalse($decision->dropped);
        self::assertSame([1], $decision->config->referenceHospitalIds);
    }

    public function testAtMostFiveReferences(): void
    {
        $scope = range(1, 9);

        self::assertFalse($this->policy($scope, range(1, 6))->effective($this->config([1, 2, 3, 4, 5, 5]), new User())->dropped);
        self::assertTrue($this->policy($scope, range(1, 6))->effective($this->config([1, 2, 3, 4, 5, 6]), new User())->dropped);
    }

    public function testEveryReferenceNeedsBenchmarking(): void
    {
        $decision = $this->policy(scope: range(1, 6), benchmarking: [1])->effective($this->config([1, 2]), new User());

        self::assertTrue($decision->dropped, 'No partial comparison: one reference without the grant drops all.');
    }

    public function testEveryReferenceBelongsToTheScope(): void
    {
        self::assertTrue($this->policy(scope: [1, 2, 3, 4], benchmarking: [9])->effective($this->config([9]), new User())->dropped);
    }

    public function testAnonymousViewersNeverGetTheComparison(): void
    {
        self::assertTrue($this->policy(scope: [1, 2, 3, 4], benchmarking: [1])->effective($this->config([1]), null)->dropped);
    }

    public function testASingleHospitalScopeAndAManualColumnAxisDropTheComparison(): void
    {
        $policy = $this->policy(scope: [1, 2, 3, 4], benchmarking: [1]);

        self::assertTrue($policy->effective($this->config([1], StatisticsFilterScope::Hospital), new User())->dropped);

        $withAxis = $this->config([1])->withAxes(AnalysisAxisRef::time(\App\Statistics\AnalysisExplorer\Domain\Enum\AnalysisDimensionGrain::Month), AnalysisAxisRef::breakdown(AnalysisDimensionKey::Urgency));
        self::assertTrue($policy->effective($withAxis, new User())->dropped);
    }

    public function testADroppedComparisonLeavesNoReferenceAndNoSeriesShare(): void
    {
        $decision = $this->policy(scope: [1, 2, 3], benchmarking: [1])->effective($this->config([1]), new User());

        self::assertSame([], $decision->config->referenceHospitalIds);
        self::assertSame([AnalysisMetricKey::AllocationCount], $decision->config->metricKeys);
    }

    public function testCandidatesAreBenchmarkingHospitalsOfTheScopeWithTheAllowedMaximum(): void
    {
        $filter = $this->filter(StatisticsFilterScope::Public);

        $candidates = $this->policy(scope: [1, 2, 3, 4, 5], benchmarking: [2, 7])->candidates(AnalysisDataSourceKey::Allocations, $filter, new User());
        self::assertSame([2 => 'Hospital 2'], $candidates->names);
        self::assertSame(2, $candidates->maxReferences, '5 hospitals in scope − 3 others.');

        self::assertFalse($this->policy(scope: [1, 2, 3], benchmarking: [1])->candidates(AnalysisDataSourceKey::Allocations, $filter, new User())->isAvailable());
        self::assertSame(5, $this->policy(scope: range(1, 20), benchmarking: [1])->candidates(AnalysisDataSourceKey::Allocations, $filter, new User())->maxReferences);
        self::assertFalse($this->policy(scope: range(1, 9), benchmarking: [1])->candidates(AnalysisDataSourceKey::Hospitals, $filter, new User())->isAvailable());
    }

    /**
     * @param list<int> $scope        hospitals of the scope
     * @param list<int> $benchmarking hospitals with Benchmarking for the viewer
     */
    private function policy(array $scope, array $benchmarking): ExplorerReferenceComparisonPolicy
    {
        self::bootKernel();
        $container = self::getContainer();

        $hospitalAccess = self::createStub(HospitalAccessInterface::class);
        $hospitalAccess->method('benchmarkingHospitalIds')->willReturn($benchmarking);
        $hospitalAccess->method('accessibleHospitalIds')->willReturn($scope);
        $hospitalAccess->method('canUseMyHospitalsScope')->willReturn(true);

        $scopeHospitals = self::createStub(ScopeHospitalIdsReaderInterface::class);
        $scopeHospitals->method('hospitalIds')->willReturnCallback(static fn (StatisticsScopeCriteria $criteria): array => $scope);

        $labels = self::createStub(GenericAnalysisEntityLabelResolverInterface::class);
        $labels->method('resolve')->willReturnCallback(static function (string $dimension, array $ids): array {
            $names = [];
            foreach ($ids as $id) {
                $names[$id] = 'Hospital '.$id;
            }

            return $names;
        });

        return new ExplorerReferenceComparisonPolicy(
            $hospitalAccess,
            $container->get(StatisticsContextFactory::class),
            $container->get(StatisticsScopeResolver::class),
            $scopeHospitals,
            $labels,
            $container->get(AnalysisViewConfigNormalizer::class),
        );
    }

    /**
     * @param list<int> $references
     */
    private function config(array $references, StatisticsFilterScope $scope = StatisticsFilterScope::Public): AnalysisViewConfig
    {
        self::bootKernel();
        $config = self::getContainer()->get(DefaultAnalysisViewFactory::class)->createDefault($this->filter($scope));

        return $config
            ->withReferenceHospitalIds($references)
            ->withMetrics([AnalysisMetricKey::AllocationCount, AnalysisMetricKey::PercentOfSeries], AnalysisMetricKey::AllocationCount);
    }

    private function filter(StatisticsFilterScope $scope): StatisticsFilter
    {
        return new StatisticsFilter(
            scope: $scope,
            hospitalId: StatisticsFilterScope::Hospital === $scope ? 1 : null,
            cohortType: null,
            period: StatisticsFilterPeriod::Last12Months,
        );
    }
}
