<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Infrastructure\Sampling;

final class PercentileSampler
{
    /**
     * @param array<string, float> $percentiles
     */
    public function sampleMinutes(array $percentiles): int
    {
        if ([] === $percentiles) {
            return random_int(5, 120);
        }

        $p25 = $percentiles['p25'] ?? $percentiles['p50'] ?? 12.0;
        $p50 = $percentiles['p50'] ?? $p25;
        $p75 = $percentiles['p75'] ?? $p50;
        $p90 = $percentiles['p90'] ?? $p75;

        $roll = (float) mt_rand() / (float) mt_getrandmax();

        $minutes = match (true) {
            $roll <= 0.25 => $this->lerp($p25, $p50, $roll / 0.25),
            $roll <= 0.50 => $this->lerp($p50, $p75, ($roll - 0.25) / 0.25),
            $roll <= 0.75 => $this->lerp($p75, $p90, ($roll - 0.50) / 0.25),
            default => $this->lerp($p90, $p90 + max(1.0, $p90 - $p75), ($roll - 0.75) / 0.25),
        };

        return max(1, (int) round($minutes));
    }

    private function lerp(float $start, float $end, float $ratio): float
    {
        $ratio = max(0.0, min(1.0, $ratio));

        return $start + (($end - $start) * $ratio);
    }
}
