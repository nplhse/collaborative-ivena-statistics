<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticsFilter;
use App\Statistics\Application\DTO\StatisticsFilterPeriod;
use App\Statistics\Application\DTO\StatisticsFilterScope;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\UI\Http\Controller\AnalysisComparisonControlsFactory;
use App\Statistics\UI\Http\Controller\AnalysisDefinitionOptionsBuilder;
use App\Statistics\UI\Http\Controller\AnalysisPagePresenter;
use App\Statistics\UI\Http\Controller\AnalysisPivotChoicesFactory;
use App\Statistics\UI\Http\Controller\AnalysisRequestModel;
use App\Statistics\UI\Http\Controller\AnalysisToolbarViewModelFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AnalysisPagePresenterTest extends KernelTestCase
{
    public function testBuildsAnalysisNavigationAndPivotChoices(): void
    {
        self::bootKernel();
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $urlBuilder = new StatisticsNavigationUrlBuilder($router);
        $presenter = new AnalysisPagePresenter(
            new AnalysisDefinitionOptionsBuilder($urlBuilder),
            new AnalysisPivotChoicesFactory($urlBuilder),
            new AnalysisToolbarViewModelFactory($urlBuilder),
            self::getContainer()->get(AnalysisComparisonControlsFactory::class),
        );

        $request = new Request(query: [
            'scope' => 'public',
            'analysis' => 'allocation_pivot',
            'rows' => 'urgency',
            'cols' => 'gender',
            'measure' => 'row_percent',
            'dimension' => 'resources',
            'chart_measure' => 'share',
            'chart' => 'bar',
            'view' => 'table',
        ]);
        $analysisRequest = new AnalysisRequestModelFactoryTestDouble()->create();
        $widget = new StatisticWidget(StatisticWidgetType::PivotTable, 'test', ['foo' => 'bar'], null, []);

        $model = $presenter->present(
            $request,
            $analysisRequest,
            'allocation_pivot',
            $widget,
            new StatisticsFilter(StatisticsFilterScope::Public, null, null, StatisticsFilterPeriod::All),
            [
                $this->definition('allocations_by_month'),
                $this->definition('pivot'),
                $this->definition('allocation_pivot'),
            ],
        );

        self::assertSame('allocation_pivot', $model->currentAnalysisKey);
        self::assertArrayHasKey('allocations_by_month', $model->analysisSelectUrls);
        self::assertArrayNotHasKey('pivot', $model->analysisSelectUrls);
        self::assertNotEmpty($model->pivotRowChoices);
        self::assertNotEmpty($model->pivotColChoices);
        self::assertNotEmpty($model->pivotMeasureChoices);
        self::assertTrue($model->toolbar->isPivotLike);
        self::assertFalse($model->toolbar->showDimensionSelector);
        self::assertFalse($model->toolbar->showChartMeasureSelector);
        self::assertArrayHasKey('pivotRowChoices', $model->analysisWidget->payload);
        self::assertArrayHasKey('pivotColChoices', $model->analysisWidget->payload);
        self::assertArrayHasKey('pivotMeasureChoices', $model->analysisWidget->payload);
    }

    private function definition(string $key): AnalysisDefinitionInterface
    {
        $definition = $this->createMock(AnalysisDefinitionInterface::class);
        $definition->method('key')->willReturn($key);
        $definition->method('labelTranslationKey')->willReturn('label.'.$key);
        $definition->method('isPivotLike')->willReturn('allocation_pivot' === $key || 'pivot' === $key);
        $definition->method('supportsDimensionSelector')->willReturn(false);
        $definition->method('supportsChartMeasureSelector')->willReturn(false);

        return $definition;
    }
}

final readonly class AnalysisRequestModelFactoryTestDouble
{
    public function create(): AnalysisRequestModel
    {
        return new AnalysisRequestModel(
            'allocation_pivot',
            'table',
            'bar',
            \App\Statistics\Application\DTO\StatisticsAnalysisDimension::Resources,
            \App\Statistics\Application\DTO\StatisticsChartMeasure::Share,
            'urgency',
            'gender',
            'row_percent',
        );
    }
}
