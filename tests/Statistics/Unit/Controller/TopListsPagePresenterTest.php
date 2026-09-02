<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\TopList\TopListComparison;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
use App\Statistics\Application\TopList\TopListLimit;
use App\Statistics\Application\TopList\TopListRankedRow;
use App\Statistics\Application\TopList\TopListRanking;
use App\Statistics\Benchmarking\UI\Form\BenchmarkSelectionFormDataFactory;
use App\Statistics\UI\Http\Controller\TopListsPagePresenter;
use App\Statistics\UI\Http\Controller\TopListsRequestModel;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class TopListsPagePresenterTest extends TestCase
{
    public function testAddsLimitFooterAndSelectUrls(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $presenter = new TopListsPagePresenter(
            new StatisticsNavigationUrlBuilder($router),
            new BenchmarkSelectionFormDataFactory(),
        );

        $request = new Request(query: ['scope' => 'public', 'report' => 'top_diagnoses', 'limit' => '10']);
        $ranking = new TopListRanking([
            new TopListRankedRow('1', 'STEMI', 4, 100.0, 1, 1),
        ], 4);
        $topLists = [$this->definition('top_diagnoses')];
        $requestModel = new TopListsRequestModel('top_diagnoses', TopListLimit::of(10));

        $model = $presenter->present($request, $topLists[0], $requestModel, $ranking, $topLists);

        self::assertSame(10, $model->currentLimit);
        self::assertSame(25, $model->currentPageSize);
        self::assertArrayHasKey('top_diagnoses', $model->topListSelectUrls);
        self::assertStringContainsString('app_stats_top_lists_show', $model->topListSelectUrls['top_diagnoses']);
        self::assertStringContainsString('app_stats_top_lists?', $model->indexUrl);
        self::assertStringNotContainsString('report=', $model->indexUrl);
        self::assertNotNull($model->topListWidget);
        self::assertArrayHasKey('rankingDepth', $model->topListWidget->payload);
        self::assertSame(10, $model->topListWidget->payload['rankingDepth']['current']);
        self::assertArrayHasKey('limitFooter', $model->topListWidget->payload);
        self::assertSame(25, $model->topListWidget->payload['limitFooter']['current']);
        self::assertArrayHasKey(100, $model->limitUrls);
        self::assertArrayHasKey('all', $model->limitUrls);
        self::assertArrayHasKey(50, $model->pageSizeUrls);
        self::assertStringContainsString('per_page=50', $model->pageSizeUrls[50]);
        self::assertFalse($model->compareEnabled);
        self::assertNotNull($model->paginator);
        self::assertFalse($model->paginator->hasToPaginate());
    }

    public function testBuildsFormDataForBothComparisonSides(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $presenter = new TopListsPagePresenter(
            new StatisticsNavigationUrlBuilder($router),
            new BenchmarkSelectionFormDataFactory(),
        );

        $request = new Request(query: ['scope' => 'public', 'period' => 'all', 'compare' => '1']);
        $ranking = new TopListRanking([
            new TopListRankedRow('1', 'STEMI', 4, 100.0, 1, 1),
        ], 4);
        $topLists = [$this->definition('top_diagnoses')];
        $requestModel = new TopListsRequestModel('top_diagnoses', TopListLimit::of(10), compare: true);
        $primaryFilter = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All);
        $comparisonFilter = new StatisticsFilter(
            StatisticsFilterScope::State,
            null,
            null,
            StatisticsFilterPeriod::Year,
            2024,
            stateId: 3,
        );
        $comparison = new TopListComparison([], [], 4, 0);

        $model = $presenter->present(
            $request,
            $topLists[0],
            $requestModel,
            $ranking,
            $topLists,
            $comparison,
            $primaryFilter,
            $comparisonFilter,
            'Public',
            'Last 12 months',
            'Hessen',
            '2024',
        );

        self::assertTrue($model->compareEnabled);
        self::assertNotNull($model->primaryFormData);
        self::assertSame('public', $model->primaryFormData->scopeGroup);
        self::assertSame('all', $model->primaryFormData->period);
        self::assertNotNull($model->comparisonFormData);
        self::assertSame('state', $model->comparisonFormData->scopeGroup);
        self::assertSame('3', $model->comparisonFormData->scopeDetail);
        self::assertSame('year', $model->comparisonFormData->period);
        self::assertSame(2024, $model->comparisonFormData->periodYear);
    }

    public function testBuildsComparisonFormDataOnSingleListForLaunchDialog(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $presenter = new TopListsPagePresenter(
            new StatisticsNavigationUrlBuilder($router),
            new BenchmarkSelectionFormDataFactory(),
        );

        $request = new Request(query: ['scope' => 'public', 'period' => 'all']);
        $ranking = new TopListRanking([], 0);
        $topLists = [$this->definition('top_diagnoses')];
        $requestModel = new TopListsRequestModel('top_diagnoses', TopListLimit::of(10));
        $primaryFilter = new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All);
        $comparisonFilter = new StatisticsFilter(
            StatisticsFilterScope::HospitalCohort,
            null,
            null,
            StatisticsFilterPeriod::All,
        );

        $model = $presenter->present(
            $request,
            $topLists[0],
            $requestModel,
            $ranking,
            $topLists,
            null,
            $primaryFilter,
            $comparisonFilter,
        );

        self::assertFalse($model->compareEnabled);
        self::assertNotNull($model->primaryFormData);
        self::assertNotNull($model->comparisonFormData);
        self::assertSame('hospital_cohort', $model->comparisonFormData->scopeGroup);
    }

    public function testPaginatesRankingWhenRowCountExceedsPageSize(): void
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $presenter = new TopListsPagePresenter(
            new StatisticsNavigationUrlBuilder($router),
            new BenchmarkSelectionFormDataFactory(),
        );

        $rows = [];
        for ($i = 1; $i <= 30; ++$i) {
            $rows[] = new TopListRankedRow((string) $i, 'Item '.$i, $i, 1.0, $i, $i);
        }
        $ranking = new TopListRanking($rows, 465);
        $slicedRanking = null;
        $definition = $this->definition('top_diagnoses', static function (TopListRanking $display) use (&$slicedRanking): StatisticWidget {
            $slicedRanking = $display;

            return new StatisticWidget(StatisticWidgetType::Table, 'top_list', ['rows' => []], null, []);
        });
        $request = new Request(query: ['scope' => 'public', 'limit' => '100', 'per_page' => '25', 'page' => '2']);
        $requestModel = new TopListsRequestModel('top_diagnoses', TopListLimit::of(100), 2, false, 25);

        $model = $presenter->present($request, $definition, $requestModel, $ranking, [$definition]);

        self::assertSame(100, $model->currentLimit);
        self::assertSame(25, $model->currentPageSize);
        self::assertNotNull($model->paginator);
        self::assertTrue($model->paginator->hasToPaginate());
        self::assertSame(2, $model->paginator->getCurrentPage());
        self::assertSame(2, $model->paginator->getLastPage());
        self::assertInstanceOf(TopListRanking::class, $slicedRanking);
        self::assertCount(5, $slicedRanking->rows);
        self::assertSame(26, $slicedRanking->rows[0]->rank);
    }

    /**
     * @param (callable(TopListRanking): StatisticWidget)|null $toTableWidget
     */
    private function definition(string $key, ?callable $toTableWidget = null): TopListDefinitionInterface
    {
        $definition = $this->createStub(TopListDefinitionInterface::class);
        $definition->method('key')->willReturn($key);
        $definition->method('labelTranslationKey')->willReturn('label.'.$key);
        $definition->method('descriptionTranslationKey')->willReturn('description.'.$key);
        $definition->method('tableLabelColumnTranslationKey')->willReturn('label.column.'.$key);
        $definition->method('allowedLimits')->willReturn([
            TopListLimit::of(10),
            TopListLimit::of(25),
            TopListLimit::of(50),
            TopListLimit::of(100),
            TopListLimit::all(),
        ]);
        $definition->method('toTableWidget')->willReturnCallback(
            $toTableWidget ?? static fn (): StatisticWidget => new StatisticWidget(StatisticWidgetType::Table, 'top_list', ['rows' => []], null, []),
        );

        return $definition;
    }
}
