<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\InsightCompare;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightPopulationFilter;
use App\Statistics\Application\Insights\InsightSubject;
use App\Statistics\Benchmarking\Application\BenchmarkSelectionQueryBuilder;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Controller\InsightCompareUrlHelper;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InsightCompareUrlHelperTest extends TestCase
{
    public function testDashboardUrlKeepsPrimaryFilterAndDropsCompareState(): void
    {
        $helper = $this->helper();
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::PERIOD => StatisticsFilterPeriod::All->value,
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => InsightDimensionKey::Indications->value,
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_DIMENSION => InsightDimensionKey::Departments->value,
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
            StatisticsQueryKeys::COMPARISON_SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::Year->value,
            'q' => 'stem',
        ]);

        $params = $this->queryParams($helper->buildDashboardUrl($request, $this->subject(InsightDimensionKey::Indications, 12, 'ACS')));

        self::assertSame('app_stats_insights_show', $params['_route']);
        self::assertSame(InsightDimensionKey::Indications->value, $params['dimension']);
        self::assertSame('12', $params['id']);
        self::assertSame(StatisticsFilterScope::Public->value, $params[StatisticsQueryKeys::SCOPE]);
        self::assertSame(StatisticsFilterPeriod::All->value, $params[StatisticsQueryKeys::PERIOD]);
        self::assertArrayNotHasKey(StatisticsQueryKeys::SUBJECT_A_DIMENSION, $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::SUBJECT_B_ID, $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARISON_SCOPE, $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARISON_PERIOD, $params);
        self::assertArrayNotHasKey('q', $params);
    }

    public function testSwapWithoutComparisonQueryOnlyExchangesSubjects(): void
    {
        $helper = $this->helper();
        $primary = $this->publicFilter(StatisticsFilterPeriod::All);
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::PERIOD => StatisticsFilterPeriod::All->value,
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => InsightDimensionKey::Indications->value,
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_DIMENSION => InsightDimensionKey::Departments->value,
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
            'view' => 'keep-me',
        ]);

        $params = $this->queryParams($helper->buildSwapUrl(
            $request,
            $this->subject(InsightDimensionKey::Indications, 12, 'ACS'),
            $this->subject(InsightDimensionKey::Departments, 3, 'Innere'),
            $primary,
            $primary,
        ));

        self::assertSame('app_stats_insights_compare', $params['_route']);
        self::assertSame(InsightDimensionKey::Departments->value, $params[StatisticsQueryKeys::SUBJECT_A_DIMENSION]);
        self::assertSame('3', $params[StatisticsQueryKeys::SUBJECT_A_ID]);
        self::assertSame(InsightDimensionKey::Indications->value, $params[StatisticsQueryKeys::SUBJECT_B_DIMENSION]);
        self::assertSame('12', $params[StatisticsQueryKeys::SUBJECT_B_ID]);
        self::assertSame(StatisticsFilterScope::Public->value, $params[StatisticsQueryKeys::SCOPE]);
        self::assertSame(StatisticsFilterPeriod::All->value, $params[StatisticsQueryKeys::PERIOD]);
        self::assertSame('keep-me', $params['view']);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARISON_SCOPE, $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARISON_PERIOD, $params);
    }

    public function testSwapWithComparisonQueryExchangesFiltersAndSubjects(): void
    {
        $helper = $this->helper();
        $primary = $this->publicFilter(StatisticsFilterPeriod::All);
        $comparison = $this->publicFilter(StatisticsFilterPeriod::Year, 2024);
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::PERIOD => StatisticsFilterPeriod::All->value,
            StatisticsQueryKeys::COMPARISON_SCOPE => StatisticsFilterScope::Public->value,
            StatisticsQueryKeys::COMPARISON_PERIOD => StatisticsFilterPeriod::Year->value,
            StatisticsQueryKeys::COMPARISON_YEAR => '2024',
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => InsightDimensionKey::Indications->value,
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_DIMENSION => InsightDimensionKey::Departments->value,
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
        ]);

        $params = $this->queryParams($helper->buildSwapUrl(
            $request,
            $this->subject(InsightDimensionKey::Indications, 12, 'ACS'),
            $this->subject(InsightDimensionKey::Departments, 3, 'Innere'),
            $primary,
            $comparison,
        ));

        self::assertSame(InsightDimensionKey::Departments->value, $params[StatisticsQueryKeys::SUBJECT_A_DIMENSION]);
        self::assertSame(InsightDimensionKey::Indications->value, $params[StatisticsQueryKeys::SUBJECT_B_DIMENSION]);
        self::assertSame(StatisticsFilterPeriod::Year->value, $params[StatisticsQueryKeys::PERIOD]);
        self::assertSame('2024', $params[StatisticsQueryKeys::YEAR]);
        self::assertSame(StatisticsFilterScope::Public->value, $params[StatisticsQueryKeys::COMPARISON_SCOPE]);
        self::assertSame(StatisticsFilterPeriod::All->value, $params[StatisticsQueryKeys::COMPARISON_PERIOD]);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARISON_YEAR, $params);
        self::assertArrayNotHasKey(StatisticsQueryKeys::COMPARE, $params);
    }

    public function testFiltersEqualIgnoresNoticeAndRedirectFlags(): void
    {
        $a = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
        );
        $b = new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            StatisticsFilterPeriod::All,
            notice: null,
            requiresPublicRedirect: true,
        );

        self::assertTrue(InsightCompareUrlHelper::filtersEqual($a, $b));
        self::assertFalse(InsightCompareUrlHelper::filtersEqual($a, $this->publicFilter(StatisticsFilterPeriod::Year, 2024)));
    }

    private function helper(): InsightCompareUrlHelper
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $route, array $params = []): string => $route.'?'.http_build_query(array_merge(['_route' => $route], $params)),
        );

        return new InsightCompareUrlHelper(
            new StatisticsNavigationUrlBuilder($router),
            new BenchmarkSelectionQueryBuilder(),
            new BenchmarkSelectionFormDataFactory(),
        );
    }

    private function subject(InsightDimensionKey $dimension, int $id, string $label): InsightSubject
    {
        return new InsightSubject(
            $dimension,
            $id,
            $label,
            InsightPopulationFilter::of($dimension->projectionColumn(), [$id]),
        );
    }

    private function publicFilter(StatisticsFilterPeriod $period, ?int $year = null): StatisticsFilter
    {
        return new StatisticsFilter(
            StatisticsFilterScope::Public,
            null,
            null,
            $period,
            $year,
        );
    }

    /**
     * @return array<string, string>
     */
    private function queryParams(string $url): array
    {
        $query = parse_url($url, PHP_URL_QUERY);
        self::assertIsString($query);

        parse_str($query, $params);

        /* @var array<string, string> $params */
        return $params;
    }
}
