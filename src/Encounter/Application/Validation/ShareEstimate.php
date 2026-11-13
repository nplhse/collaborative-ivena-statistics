<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

/**
 * @psalm-api Read by the validation templates and the evaluation command.
 *
 * Observed share x / n with its Wilson score interval at 95 % (#1057). With few judged items the interval
 * is wide; it is what the report shows next to the share instead of a bare percentage.
 */
final readonly class ShareEstimate
{
    private const float Z = 1.959964;

    public float $share;

    public float $lower;

    public float $upper;

    public function __construct(int $hits, int $total)
    {
        if ($total < 1 || $hits < 0 || $hits > $total) {
            throw new \InvalidArgumentException('Expected 0 <= hits <= total and total >= 1.');
        }

        $n = (float) $total;
        $share = (float) $hits / $n;
        $z2 = self::Z * self::Z;
        $denominator = 1.0 + $z2 / $n;
        $center = ($share + $z2 / (2.0 * $n)) / $denominator;
        $half = self::Z * sqrt($share * (1.0 - $share) / $n + $z2 / (4.0 * $n * $n)) / $denominator;

        $this->share = $share;
        // At 0 and 1 the bound is exact; rounding would leave it a hair inside.
        $this->lower = 0 === $hits ? 0.0 : max(0.0, $center - $half);
        $this->upper = $hits === $total ? 1.0 : min(1.0, $center + $half);
    }

    public static function of(int $hits, int $total): ?self
    {
        return $total > 0 ? new self($hits, $total) : null;
    }
}
