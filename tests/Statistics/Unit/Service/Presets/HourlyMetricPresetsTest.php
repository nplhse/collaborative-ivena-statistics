<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Service\Presets;

use App\Statistics\Infrastructure\Presets\HourlyMetricPresets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HourlyMetricPresetsTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('presets')]
    public function testMetricsFor(string $preset, array $expected): void
    {
        // Arrange & Act
        $out = HourlyMetricPresets::metricsFor($preset);

        // Assert
        self::assertSame($expected, $out);
    }

    /**
     * @return iterable<array{0:string,1:list<string>}>
     */
    public static function presets(): iterable
    {
        yield ['total',    ['total']];
        yield ['gender',   ['gender_m', 'gender_w', 'gender_d']];
        yield ['urgency',  ['urg_1', 'urg_2', 'urg_3']];
        yield ['clinical', ['is_ventilated', 'is_cpr', 'is_shock', 'is_pregnant', 'with_physician', 'infectious']];
        yield ['resources', ['cathlab', 'resus']];
        yield ['unknown',  ['total']]; // default
    }

    public function testAllReturnsStableList(): void
    {
        // Arrange & Act
        $all = HourlyMetricPresets::all();

        // Assert
        self::assertSame(
            [
                ['value' => 'total',    'label' => 'Total'],
                ['value' => 'gender',   'label' => 'Gender'],
                ['value' => 'urgency',  'label' => 'Urgency'],
                ['value' => 'clinical', 'label' => 'Clinical'],
                ['value' => 'resources', 'label' => 'Resources'],
            ],
            $all
        );
    }
}
