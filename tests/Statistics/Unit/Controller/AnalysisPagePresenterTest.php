<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\UI\Http\Controller\AnalysisPagePresenter;
use App\Statistics\UI\Http\Controller\AnalysisRequestModel;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AnalysisPagePresenterTest extends TestCase
{
    public function testBuildsAnalysisNavigationAndPivotChoices(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $presenter = new AnalysisPagePresenter(new StatisticsNavigationUrlBuilder($router));

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
