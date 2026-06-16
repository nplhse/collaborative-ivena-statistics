<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WeightedCategoricalSamplerTest extends TestCase
{
    #[Test]
    public function normalizeScalesWeightsToOne(): void
    {
        $sampler = new WeightedCategoricalSampler();

        $normalized = $sampler->normalize(['a' => 2, 'b' => 2]);

        self::assertEqualsWithDelta(0.5, $normalized['a'], 0.0001);
        self::assertEqualsWithDelta(0.5, $normalized['b'], 0.0001);
    }

    #[Test]
    public function sampleReturnsOnlyKnownLabels(): void
    {
        $sampler = new WeightedCategoricalSampler();

        $label = $sampler->sample(['red' => 1, 'blue' => 3]);

        self::assertContains($label, ['red', 'blue']);
    }

    #[Test]
    public function sampleCoercesNumericYamlKeysToString(): void
    {
        $sampler = new WeightedCategoricalSampler();

        $label = $sampler->sample([1 => 1, 2 => 1, 3 => 1]);

        self::assertIsString($label);
        self::assertContains($label, ['1', '2', '3']);
    }
}
