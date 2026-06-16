<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure\Sampling;

final class WeightedCategoricalSampler
{
    /**
     * @param array<int|string, float|int> $weights
     */
    public function sample(array $weights): string
    {
        if ([] === $weights) {
            throw new \InvalidArgumentException('Cannot sample from an empty distribution.');
        }

        $normalized = $this->normalize($weights);
        $target = (float) mt_rand() / (float) mt_getrandmax();
        $cursor = 0.0;

        foreach ($normalized as $label => $weight) {
            $cursor += $weight;
            if ($target <= $cursor) {
                return $this->stringifyKey($label);
            }
        }

        $lastLabel = array_key_last($normalized);
        if (null === $lastLabel) {
            throw new \RuntimeException('Cannot sample from an empty normalized distribution.');
        }

        return $this->stringifyKey($lastLabel);
    }

    private function stringifyKey(int|string $key): string
    {
        return (string) $key;
    }

    public function sampleBernoulli(float $probability): bool
    {
        $probability = max(0.0, min(1.0, $probability));

        return (float) mt_rand() / (float) mt_getrandmax() < $probability;
    }

    /**
     * @param array<int|string, float|int> $weights
     *
     * @return array<int|string, float>
     */
    public function normalize(array $weights): array
    {
        $positive = [];
        $sum = 0.0;
        foreach ($weights as $label => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $positive[$this->stringifyKey($label)] = (float) $weight;
            $sum += (float) $weight;
        }

        if ($sum <= 0.0) {
            throw new \InvalidArgumentException('Distribution weights must sum to a positive value.');
        }

        $normalized = [];
        foreach ($positive as $label => $weight) {
            $normalized[$label] = $weight / $sum;
        }

        return $normalized;
    }
}
