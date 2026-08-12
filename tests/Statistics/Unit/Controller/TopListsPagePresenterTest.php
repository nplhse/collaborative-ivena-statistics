<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\Application\DTO\StatisticWidget;
use App\Statistics\Application\DTO\StatisticWidgetType;
use App\Statistics\Application\TopList\TopListDefinitionInterface;
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
        $presenter = new TopListsPagePresenter(new StatisticsNavigationUrlBuilder($router));

        $request = new Request(query: ['scope' => 'public', 'report' => 'top_diagnoses', 'limit' => '10']);
        $topListWidget = new StatisticWidget(StatisticWidgetType::Table, 'top_list', ['rows' => []], null, []);
        $topLists = [$this->definition('top_diagnoses')];
        $requestModel = new TopListsRequestModel('top_diagnoses', 10);

        $model = $presenter->present($request, $topLists[0], $requestModel, $topListWidget, $topLists);

        self::assertSame(10, $model->currentLimit);
        self::assertArrayHasKey('top_diagnoses', $model->topListSelectUrls);
        self::assertArrayHasKey('limitFooter', $model->topListWidget->payload);
        self::assertSame(10, $model->topListWidget->payload['limitFooter']['current']);
    }

    private function definition(string $key): TopListDefinitionInterface
    {
        $definition = $this->createStub(TopListDefinitionInterface::class);
        $definition->method('key')->willReturn($key);
        $definition->method('labelTranslationKey')->willReturn('label.'.$key);
        $definition->method('descriptionTranslationKey')->willReturn('description.'.$key);
        $definition->method('allowedLimits')->willReturn([10, 25, 50]);

        return $definition;
    }
}
