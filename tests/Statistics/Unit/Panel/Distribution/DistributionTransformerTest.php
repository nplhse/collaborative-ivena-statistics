<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Panel\Distribution\CodeLabelMapperInterface;
use App\Statistics\Application\Panel\Distribution\DistributionTransformer;
use PHPUnit\Framework\TestCase;

final class DistributionTransformerTest extends TestCase
{
    public function testSimpleDistributionPercentagesSumToOneHundred(): void
    {
        $primary = $this->createMapper([
            1 => 'A',
            2 => 'B',
        ]);
        $transformer = new DistributionTransformer();

        $view = $transformer->transform(
            [
                ['pk' => 1, 'gk' => null, 'value' => 25],
                ['pk' => 2, 'gk' => null, 'value' => 75],
            ],
            $primary,
            null,
            false,
            'Count',
        );

        self::assertSame(['A', 'B'], $view->labels);
        self::assertCount(1, $view->series);
        self::assertSame('Count', $view->series[0]['name']);
        self::assertSame([25, 75], $view->series[0]['values']);
        self::assertSame([25.0, 75.0], $view->series[0]['percentages']);
        self::assertFalse($view->grouped);
    }

    public function testGroupedPercentagesArePerPrimaryCategory(): void
    {
        $primary = $this->createMapper([1 => 'U1', 2 => 'U2']);
        $group = $this->createMapper([10 => 'G10', 20 => 'G20']);
        $transformer = new DistributionTransformer();

        $view = $transformer->transform(
            [
                ['pk' => 1, 'gk' => 10, 'value' => 10],
                ['pk' => 1, 'gk' => 20, 'value' => 30],
                ['pk' => 2, 'gk' => 10, 'value' => 50],
                ['pk' => 2, 'gk' => 20, 'value' => 50],
            ],
            $primary,
            $group,
            true,
            'ignored',
        );

        self::assertTrue($view->grouped);
        self::assertSame(['U1', 'U2'], $view->labels);
        self::assertCount(2, $view->series);

        $byName = [];
        foreach ($view->series as $s) {
            $byName[$s['name']] = $s;
        }

        self::assertSame([10, 50], $byName['G10']['values']);
        self::assertSame([30, 50], $byName['G20']['values']);
        self::assertSame([25.0, 50.0], $byName['G10']['percentages']);
        self::assertSame([75.0, 50.0], $byName['G20']['percentages']);
    }

    /**
     * @param array<int, string> $map
     */
    private function createMapper(array $map): CodeLabelMapperInterface
    {
        return new readonly class($map) implements CodeLabelMapperInterface {
            /**
             * @param array<int, string> $map
             */
            public function __construct(private array $map)
            {
            }

            public function label(?int $code): string
            {
                if (null === $code) {
                    return 'null';
                }

                return $this->map[$code] ?? '?';
            }
        };
    }
}
