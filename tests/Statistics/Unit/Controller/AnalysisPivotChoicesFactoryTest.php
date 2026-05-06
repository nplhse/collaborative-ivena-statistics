<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Controller;

use App\Statistics\UI\Http\Controller\AnalysisPivotChoicesFactory;
use App\Statistics\UI\Http\Navigation\StatisticsNavigationUrlBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AnalysisPivotChoicesFactoryTest extends TestCase
{
    public function testBuildsAllocationPivotChoices(): void
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $routeName, array $params): string => sprintf('%s?%s', $routeName, http_build_query($params)),
        );
        $factory = new AnalysisPivotChoicesFactory(new StatisticsNavigationUrlBuilder($router));
        $request = new Request(query: [
            'analysis' => 'allocation_pivot',
            'rows' => 'urgency',
            'cols' => 'gender',
            'measure' => 'row_percent',
        ]);

        $choices = $factory->build($request, 'allocation_pivot');

        self::assertNotEmpty($choices['rows']);
        self::assertNotEmpty($choices['cols']);
        self::assertNotEmpty($choices['measures']);
    }
}
