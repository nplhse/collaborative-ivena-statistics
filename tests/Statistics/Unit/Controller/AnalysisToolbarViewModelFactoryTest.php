<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\Analysis\AnalysisDefinitionInterface;
use App\Statistics\Application\DTO\StatisticsAnalysisDimension;
use App\Statistics\Application\DTO\StatisticsChartMeasure;
use App\Statistics\UI\Http\Controller\AnalysisRequestModel;
use App\Statistics\UI\Http\Controller\AnalysisToolbarViewModelFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AnalysisToolbarViewModelFactoryTest extends TestCase
{
    public function testBuildsToolbarModel(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $factory = new AnalysisToolbarViewModelFactory(new StatisticsNavigationUrlBuilder($router));

        $definition = $this->createMock(AnalysisDefinitionInterface::class);
        $definition->method('isPivotLike')->willReturn(false);
        $definition->method('supportsDimensionSelector')->willReturn(true);
        $definition->method('supportsChartMeasureSelector')->willReturn(true);

        $model = $factory->create(
            new Request(query: ['analysis' => 'allocations_by_month']),
            $definition,
            new AnalysisRequestModel(
                'allocations_by_month',
                'chart',
                'bar',
                StatisticsAnalysisDimension::Resources,
                StatisticsChartMeasure::Share,
                null,
                null,
                null,
            ),
        );

        self::assertFalse($model->isPivotLike);
        self::assertTrue($model->showDimensionSelector);
        self::assertTrue($model->showChartMeasureSelector);
        self::assertStringContainsString('view=chart', $model->viewChartUrl);
    }
}
