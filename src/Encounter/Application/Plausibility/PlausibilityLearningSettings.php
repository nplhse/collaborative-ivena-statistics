<?php

declare(strict_types=1);

namespace App\Encounter\Application\Plausibility;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Parameters of the learning job of the plausibility table (`app.encounter.plausibility`, ADR 033, #1155).
 *
 * - `smoothing` (α): Laplace pseudo count added to every cell count of a row, so a share is (count + α) / (row total + α·K)
 *   with K distinct diagrams observed in the row.
 * - `shrinkage` (k): pseudo observations of the seed points; a cell with n observations gets
 *   (n · estimate + k · seed) / (n + k).
 * - `minObservations`: observations (positives + negatives) a cell needs before it gets learned points; the same
 *   minimum decides in the lookup whether an exact cell replaces the problem-area cell.
 * - `fold`: how the cross-fitted replay splits the data; `alternating_days` puts even and odd days (counted from
 *   1970-01-01) into two folds.
 */
final readonly class PlausibilityLearningSettings
{
    public const string FOLD_ALTERNATING_DAYS = 'alternating_days';

    /** @psalm-suppress PossiblyUnusedMethod Wired by the container. */
    public function __construct(
        #[Autowire(param: 'app.encounter.plausibility.learning.smoothing')]
        public float $smoothing = 1.0,
        #[Autowire(param: 'app.encounter.plausibility.learning.shrinkage')]
        public float $shrinkage = 10.0,
        #[Autowire(param: 'app.encounter.plausibility.min_observations')]
        public int $minObservations = 10,
        #[Autowire(param: 'app.encounter.plausibility.learning.fold')]
        public string $fold = self::FOLD_ALTERNATING_DAYS,
    ) {
        if ($smoothing <= 0.0 || $shrinkage < 0.0 || $minObservations < 1) {
            throw new \InvalidArgumentException('Expected smoothing > 0, shrinkage >= 0 and at least one observation.');
        }

        if (self::FOLD_ALTERNATING_DAYS !== $fold) {
            throw new \InvalidArgumentException(\sprintf('Unknown fold rule "%s".', $fold));
        }
    }

    /**
     * Number of folds of the cross-fitted replay.
     */
    public function folds(): int
    {
        return 2;
    }

    /**
     * Fold of an arrival day (YYYY-MM-DD): 0 for even, 1 for odd days counted from 1970-01-01.
     */
    public function foldOf(string $day): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone('UTC'));
        if (!$date instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(\sprintf('Expected a day as YYYY-MM-DD, got "%s".', $day));
        }

        return intdiv($date->getTimestamp(), 86400) % $this->folds();
    }

    /**
     * The parameters as stored with a learned snapshot.
     *
     * @return array{smoothing: float, shrinkage: float, min_observations: int, fold: string}
     */
    public function toArray(): array
    {
        return [
            'smoothing' => $this->smoothing,
            'shrinkage' => $this->shrinkage,
            'min_observations' => $this->minObservations,
            'fold' => $this->fold,
        ];
    }
}
