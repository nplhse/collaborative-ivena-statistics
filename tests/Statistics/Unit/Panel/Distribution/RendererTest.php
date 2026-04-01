<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    public function testRenderAbsoluteUsesValuesAndNoPercentYAxis(): void
    {
        $result = new Renderer()->render($this->distribution(), 'absolute');

        self::assertFalse($result['chart']['chart']['stacked']);
        self::assertNull($result['chart']['chart']['stackType']);
        self::assertSame([], $result['chart']['yaxis']);
        self::assertSame([10, 20], $result['chart']['series'][0]['data']);
    }

    public function testRenderPercentOfTotalUsesPercentagesWithoutStacking(): void
    {
        $result = new Renderer()->render($this->distribution(), 'percent_of_total');

        self::assertFalse($result['chart']['chart']['stacked']);
        self::assertNull($result['chart']['chart']['stackType']);
        self::assertSame(['max' => 100], $result['chart']['yaxis']);
        self::assertSame([25.0, 50.0], $result['chart']['series'][0]['data']);
    }

    public function testRenderStackedUsesValuesWithStackedBars(): void
    {
        $result = new Renderer()->render($this->distribution(), 'stacked');

        self::assertTrue($result['chart']['chart']['stacked']);
        self::assertNull($result['chart']['chart']['stackType']);
        self::assertSame([10, 20], $result['chart']['series'][0]['data']);
    }

    public function testRenderPercentUsesPercentagesWithHundredPercentStack(): void
    {
        $result = new Renderer()->render($this->distribution(), 'percent');

        self::assertTrue($result['chart']['chart']['stacked']);
        self::assertSame('100%', $result['chart']['chart']['stackType']);
        self::assertSame(['max' => 100], $result['chart']['yaxis']);
        self::assertSame([25.0, 50.0], $result['chart']['series'][0]['data']);
    }

    /**
     * @return array{
     *     labels: list<string>,
     *     series: list<array{name: string, values: list<int>, percentages: list<float>}>,
     *     table: list<array{dimensionLabel: string, groupLabel: string|null, value: int, percent: float, isTotal: bool}>
     * }
     */
    private function distribution(): array
    {
        return [
            'labels' => ['A', 'B'],
            'series' => [
                [
                    'name' => 'S1',
                    'values' => [10, 20],
                    'percentages' => [25.0, 50.0],
                ],
            ],
            'table' => [
                [
                    'dimensionLabel' => 'A',
                    'groupLabel' => null,
                    'value' => 10,
                    'percent' => 25.0,
                    'isTotal' => false,
                ],
            ],
        ];
    }
}
