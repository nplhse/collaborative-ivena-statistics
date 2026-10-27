<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern;

use App\DataFixtures\Pattern\Infrastructure\Sampling\WeightedCategoricalSampler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

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

        $label = $sampler->sample(['red' => 1, 'blue' => 3], new Randomizer(new Mt19937(1)));

        self::assertContains($label, ['red', 'blue']);
    }

    #[Test]
    public function sampleCoercesNumericYamlKeysToString(): void
    {
        $sampler = new WeightedCategoricalSampler();

        $label = $sampler->sample([1 => 1, 2 => 1, 3 => 1], new Randomizer(new Mt19937(1)));

        self::assertIsString($label);
        self::assertContains($label, ['1', '2', '3']);
    }

    #[Test]
    public function theSameStreamGivesTheSameSequence(): void
    {
        $sampler = new WeightedCategoricalSampler();
        $draw = static function (int $seed) use ($sampler): array {
            $random = new Randomizer(new Mt19937($seed));
            $labels = [];
            for ($i = 0; $i < 30; ++$i) {
                $labels[] = $sampler->sample(['a' => 1, 'b' => 1, 'c' => 1], $random);
                $labels[] = $sampler->sampleBernoulli(0.5, $random) ? 'yes' : 'no';
            }

            return $labels;
        };

        self::assertSame($draw(7), $draw(7));
        self::assertNotSame($draw(7), $draw(8));
    }

    #[Test]
    public function bernoulliHonoursTheBounds(): void
    {
        $sampler = new WeightedCategoricalSampler();
        $random = new Randomizer(new Mt19937(3));

        for ($i = 0; $i < 20; ++$i) {
            self::assertFalse($sampler->sampleBernoulli(0.0, $random));
            self::assertTrue($sampler->sampleBernoulli(1.0, $random));
        }
    }
}
