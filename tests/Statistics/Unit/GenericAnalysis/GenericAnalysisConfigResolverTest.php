<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticsPeriodBounds;
use App\Statistics\Application\DTO\StatisticsScopeCriteria;
use App\Statistics\GenericAnalysis\Application\AnalysisPresetRegistry;
use App\Statistics\GenericAnalysis\Application\Contract\CustomAnalysisAccessInterface;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisConfigResolver;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisDimensionPolicy;
use App\Statistics\GenericAnalysis\Application\GenericAnalysisMetricRequestResolver;
use App\Statistics\GenericAnalysis\Application\MetricCompatibilityChecker;
use App\Statistics\GenericAnalysis\Domain\Exception\UnknownAnalysisDimensionException;
use App\Statistics\GenericAnalysis\Registry\AnalysisDataSourceRegistry;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use App\Statistics\GenericAnalysis\Registry\MetricRegistry;
use App\Statistics\GenericAnalysis\UI\Http\Navigation\GenericAnalysisQueryKeys;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

final class GenericAnalysisConfigResolverTest extends TestCase
{
    private GenericAnalysisConfigResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->createResolver();
    }

    private function allowCustomAnalysis(): CustomAnalysisAccessInterface
    {
        $access = $this->createMock(CustomAnalysisAccessInterface::class);
        $access->method('canUseCustomAnalysis')->willReturn(true);

        return $access;
    }

    private function denyCustomAnalysis(): CustomAnalysisAccessInterface
    {
        $access = $this->createMock(CustomAnalysisAccessInterface::class);
        $access->method('canUseCustomAnalysis')->willReturn(false);

        return $access;
    }

    private function createResolver(
        ?HospitalAccessInterface $hospitalAccess = null,
        ?CustomAnalysisAccessInterface $customAnalysisAccess = null,
    ): GenericAnalysisConfigResolver {
        if (!$hospitalAccess instanceof HospitalAccessInterface) {
            $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
            $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(true);
        }

        $metricRegistry = new MetricRegistry();
        $dimensionRegistry = new DimensionRegistry();
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Custom analysis');

        return new GenericAnalysisConfigResolver(
            new AnalysisPresetRegistry(),
            $dimensionRegistry,
            new GenericAnalysisDimensionPolicy($hospitalAccess, $dimensionRegistry),
            new GenericAnalysisMetricRequestResolver(
                $metricRegistry,
                $dimensionRegistry,
                new MetricCompatibilityChecker($metricRegistry, $dimensionRegistry),
            ),
            $customAnalysisAccess ?? $this->allowCustomAnalysis(),
            GenericAnalysisTestFixtures::configurationValidator($dimensionRegistry, $metricRegistry, $translator),
            new AnalysisDataSourceRegistry(),
            $translator,
        );
    }

    private function publicFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );
    }

    public function testResolvesPresetWithoutOverrides(): void
    {
        $request = Request::create('/statistics/generic-analysis/allocations_by_month', Request::METHOD_GET, [
            'scope' => 'public',
        ]);

        $config = $this->resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertFalse($config->isCustom);
        self::assertSame('month', $config->primaryDimensionKey);
        self::assertSame('Allocations by month', $config->displayTitle);
        self::assertSame(['count'], $config->query->resolvedMetricKeys());
    }

    public function testAppliesMetricOverridesOnPresetRoute(): void
    {
        $request = Request::create('/statistics/generic-analysis/allocations_by_month', Request::METHOD_GET, [
            'ga_metrics' => ['count', 'percent_of_total'],
        ]);

        $config = $this->resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertFalse($config->isCustom);
        self::assertSame(['count', 'percent_of_total'], $config->query->resolvedMetricKeys());
    }

    public function testResolvesCustomWhenOverridesDiffer(): void
    {
        $request = Request::create('/statistics/generic-analysis/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::PRIMARY => 'hour',
        ]);

        $config = $this->resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertTrue($config->isCustom);
        self::assertSame('hour', $config->primaryDimensionKey);
        self::assertSame('allocations_by_month', $config->referencePresetKey);
    }

    public function testDataSourceOverrideRemapsInvalidAllocationDimensions(): void
    {
        $request = Request::create('/statistics/analytics/view/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::DATA_SOURCE => 'hospitals',
        ]);

        $config = $this->resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertTrue($config->isCustom);
        self::assertSame('hospital_tier', $config->primaryDimensionKey);
        self::assertNull($config->seriesDimensionKey);
    }

    public function testDataSourceOverrideRemapsExplicitInvalidDimensions(): void
    {
        $request = Request::create('/statistics/analytics/view/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::DATA_SOURCE => 'hospitals',
            GenericAnalysisQueryKeys::PRIMARY => 'month',
            GenericAnalysisQueryKeys::SERIES => 'weekday',
        ]);

        $config = $this->resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertTrue($config->isCustom);
        self::assertSame('hospital_tier', $config->primaryDimensionKey);
        self::assertNull($config->seriesDimensionKey);
    }

    public function testCustomRouteUsesQueryDimensions(): void
    {
        $request = Request::create('/statistics/generic-analysis/custom', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::PRIMARY => 'weekday',
            GenericAnalysisQueryKeys::REF_PRESET => 'allocations_by_hour',
        ]);

        $config = $this->resolver->resolve(
            'custom',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );

        self::assertTrue($config->isCustom);
        self::assertSame('weekday', $config->primaryDimensionKey);
        self::assertSame('allocations_by_hour', $config->referencePresetKey);
    }

    public function testUnknownDimensionThrows(): void
    {
        $request = Request::create('/statistics/generic-analysis/custom', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::PRIMARY => 'evil',
        ]);

        $this->expectException(UnknownAnalysisDimensionException::class);

        $this->resolver->resolve(
            'custom',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );
    }

    public function testRejectsCustomOverridesForNonParticipant(): void
    {
        $resolver = $this->createResolver(customAnalysisAccess: $this->denyCustomAnalysis());
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);

        $request = Request::create('/statistics/generic-analysis/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::PRIMARY => 'hour',
        ]);

        $this->expectException(AccessDeniedException::class);

        $resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            $user,
        );
    }

    public function testDisallowedDimensionForScopeThrows(): void
    {
        $hospitalAccess = $this->createMock(HospitalAccessInterface::class);
        $hospitalAccess->method('isAdminHospitalScopeUser')->willReturn(false);

        $resolver = $this->createResolver($hospitalAccess, $this->allowCustomAnalysis());

        $request = Request::create('/statistics/generic-analysis/custom', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::PRIMARY => 'hospital',
        ]);

        $this->expectException(UnknownAnalysisDimensionException::class);

        $resolver->resolve(
            'custom',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            null,
        );
    }

    public function testFindMatchingSelectablePreset(): void
    {
        $match = $this->resolver->findMatchingSelectablePreset('month', null, false);

        self::assertNotNull($match);
        self::assertSame('allocations_by_month', $match->key);
    }

    public function testHospitalCompareModeSetsPopulationGroupSeries(): void
    {
        $request = Request::create('/statistics/analytics/view/allocations_by_month', Request::METHOD_GET, [
            GenericAnalysisQueryKeys::DATA_SOURCE => 'hospitals',
            GenericAnalysisQueryKeys::HOSPITAL_POPULATION => 'compare',
            GenericAnalysisQueryKeys::PRIMARY => 'hospital_tier',
            GenericAnalysisQueryKeys::METRICS => ['hospital_count'],
            GenericAnalysisQueryKeys::REF_PRESET => 'allocations_by_month',
        ]);

        $config = $this->resolver->resolve(
            'allocations_by_month',
            $request,
            StatisticsScopeCriteria::public(),
            new StatisticsPeriodBounds(null),
            $this->publicFilter(),
            $this->createMock(User::class),
        );

        self::assertSame('hospital_population_group', $config->query->seriesDimensionKey);
        self::assertSame('hospital_population_group', $config->seriesDimensionKey);
    }
}
