<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\InsightCompare;

use App\Statistics\Application\ComparisonFilterInputFactory;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\InsightCompare\InsightCompareFilterResolver;
use App\Statistics\Application\StatisticsFilterFactory;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InsightCompareFilterResolverTest extends TestCase
{
    public function testHasComparisonQueryIgnoresBlankValues(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::COMPARISON_PERIOD => '   ',
        ]);

        self::assertFalse($this->resolver()->hasComparisonQuery($request));
    }

    public function testHasComparisonQueryWhenAnyComparisonKeyIsSet(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::Year->value,
        ]);

        self::assertTrue($this->resolver()->hasComparisonQuery($request));
    }

    public function testResolveReturnsPrimaryWhenComparisonQueryIsMissing(): void
    {
        $primary = $this->publicAllFilter();
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::PERIOD => StatisticsFilterPeriod::All->value,
        ]);

        self::assertSame($primary, $this->resolver()->resolve($request, null, $primary));
    }

    public function testCopiesPrimaryScopeWhenOnlyComparisonPeriodIsSet(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::Year->value,
        ]);

        $query = $this->resolver()->comparisonQueryBag($request, $this->publicAllFilter());

        self::assertSame(StatisticsFilterScope::Public->value, $query->getString(StatisticsQueryKeys::COMPARISON_SCOPE));
        self::assertSame(StatisticsFilterPeriod::Year->value, $query->getString(StatisticsQueryKeys::COMPARISON_PERIOD));
    }

    public function testDoesNotOverwriteExplicitComparisonScope(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::COMPARISON_SCOPE => StatisticsFilterScope::State->value.':7',
            StatisticsQueryKeys::COMPARISON_STATE => '7',
            StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::Year->value,
        ]);

        $query = $this->resolver()->comparisonQueryBag($request, $this->publicAllFilter());

        self::assertSame(StatisticsFilterScope::State->value.':7', $query->getString(StatisticsQueryKeys::COMPARISON_SCOPE));
        self::assertSame('7', $query->getString(StatisticsQueryKeys::COMPARISON_STATE));
        self::assertSame(StatisticsFilterPeriod::Year->value, $query->getString(StatisticsQueryKeys::COMPARISON_PERIOD));
        self::assertSame('', $query->getString(StatisticsQueryKeys::SCOPE));
    }

    private function resolver(): InsightCompareFilterResolver
    {
        $filterFactory = new \ReflectionClass(StatisticsFilterFactory::class)->newInstanceWithoutConstructor();
        self::assertInstanceOf(StatisticsFilterFactory::class, $filterFactory);

        return new InsightCompareFilterResolver(
            new ComparisonFilterInputFactory(),
            $filterFactory,
            new BenchmarkSelectionFormDataFactory(),
            new BenchmarkSelectionQueryBuilder(),
        );
    }

    private function publicAllFilter(): StatisticsFilter
    {
        return new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );
    }
}
