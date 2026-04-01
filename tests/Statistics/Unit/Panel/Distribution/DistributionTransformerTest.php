<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Panel\Distribution;

use App\Statistics\Application\Mapping\ValueMapper;
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

        $result = $transformer->transform(
            [
                ['dimension_key' => 1, 'group_key' => null, 'value' => 25],
                ['dimension_key' => 2, 'group_key' => null, 'value' => 75],
            ],
            $primary,
            null,
        );

        self::assertSame(['A', 'B'], $result['labels']);
        self::assertCount(1, $result['series']);
        self::assertSame('Gesamt', $result['series'][0]['name']);
        self::assertSame([25, 75], $result['series'][0]['values']);
        self::assertSame([25.0, 75.0], $result['series'][0]['percentages']);
        self::assertCount(3, $result['table']);
        self::assertSame('Total', $result['table'][2]['dimensionLabel']);
        self::assertTrue($result['table'][2]['isTotal']);
        self::assertSame(100, $result['table'][2]['value']);
    }

    public function testGroupedPercentagesArePerPrimaryCategory(): void
    {
        $primary = $this->createMapper([1 => 'U1', 2 => 'U2']);
        $group = $this->createMapper([10 => 'G10', 20 => 'G20']);
        $transformer = new DistributionTransformer();

        $result = $transformer->transform(
            [
                ['dimension_key' => 1, 'group_key' => 10, 'value' => 10],
                ['dimension_key' => 1, 'group_key' => 20, 'value' => 30],
                ['dimension_key' => 2, 'group_key' => 10, 'value' => 50],
                ['dimension_key' => 2, 'group_key' => 20, 'value' => 50],
            ],
            $primary,
            $group,
        );

        self::assertSame(['U1', 'U2'], $result['labels']);
        self::assertCount(2, $result['series']);

        $byName = [];
        foreach ($result['series'] as $s) {
            $byName[$s['name']] = $s;
        }

        self::assertSame([10, 50], $byName['G10']['values']);
        self::assertSame([30, 50], $byName['G20']['values']);
        self::assertSame([25.0, 50.0], $byName['G10']['percentages']);
        self::assertSame([75.0, 50.0], $byName['G20']['percentages']);
        self::assertCount(5, $result['table']);
        self::assertTrue($result['table'][4]['isTotal']);
    }

    /**
     * @param array<int, string> $map
     */
    private function createMapper(array $map): ValueMapper
    {
        return new readonly class($map) implements ValueMapper {
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
