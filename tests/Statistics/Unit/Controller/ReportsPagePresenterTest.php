<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\Report\ReportDefinitionInterface;
use App\Statistics\UI\Http\Controller\ReportsPagePresenter;
use App\Statistics\UI\Http\Controller\ReportsRequestModel;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ReportsPagePresenterTest extends TestCase
{
    public function testAddsLimitFooterAndSelectUrls(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $presenter = new ReportsPagePresenter(new StatisticsNavigationUrlBuilder($router));

        $request = new Request(query: ['scope' => 'public', 'report' => 'top_diagnoses', 'limit' => '10']);
        $reportWidget = new StatisticWidget(StatisticWidgetType::Table, 'report', ['rows' => []], null, []);
        $reports = [$this->definition('top_diagnoses')];
        $requestModel = new ReportsRequestModel('top_diagnoses', 10);

        $model = $presenter->present($request, $reports[0], $requestModel, $reportWidget, $reports);

        self::assertSame(10, $model->currentLimit);
        self::assertArrayHasKey('top_diagnoses', $model->reportSelectUrls);
        self::assertArrayHasKey('limitFooter', $model->reportWidget->payload);
        self::assertSame(10, $model->reportWidget->payload['limitFooter']['current']);
    }

    private function definition(string $key): ReportDefinitionInterface
    {
        $definition = $this->createMock(ReportDefinitionInterface::class);
        $definition->method('key')->willReturn($key);
        $definition->method('labelTranslationKey')->willReturn('label.'.$key);
        $definition->method('descriptionTranslationKey')->willReturn('description.'.$key);
        $definition->method('allowedLimits')->willReturn([10, 25, 50]);

        return $definition;
    }
}
