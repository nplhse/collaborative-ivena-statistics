<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Score bands of the match validation (#1057): each band runs from its lower bound up to, not including,
 * the next one; the last band includes 1.0. A score below the first bound falls into a band from 0.0.
 * Labels have two decimals, for example "0.90–0.99" and "0.99–1.00".
 */
final readonly class ScoreBands
{
    /** @var list<float> */
    public array $lowerBounds;

    /**
     * @param list<float|int> $lowerBounds strictly ascending, within 0 … 1
     */
    public function __construct(
        #[Autowire(param: 'app.encounter.validation.score_bands')]
        array $lowerBounds = [0.50, 0.70, 0.90, 0.99],
    ) {
        $bounds = array_map(static fn (int|float $bound): float => (float) $bound, $lowerBounds);
        $sorted = $bounds;
        sort($sorted);
        if ([] === $bounds || $sorted !== $bounds || \count(array_unique($bounds, \SORT_REGULAR)) !== \count($bounds) || $bounds[0] < 0.0 || $bounds[\count($bounds) - 1] >= 1.0) {
            throw new \InvalidArgumentException('Score bands need strictly ascending lower bounds in [0, 1).');
        }

        $this->lowerBounds = $bounds;
    }

    public function bandOf(float $score): string
    {
        if ($score < $this->lowerBounds[0]) {
            return $this->label(0.0, $this->lowerBounds[0]);
        }

        $label = '';
        foreach ($this->lowerBounds as $index => $lower) {
            if ($score >= $lower) {
                $label = $this->label($lower, $this->lowerBounds[$index + 1] ?? 1.0);
            }
        }

        return $label;
    }

    /**
     * Labels from the highest band down, each with its bounds.
     *
     * @return list<array{label: string, from: float, to: float}>
     */
    public function bands(): array
    {
        $bands = [];
        foreach ($this->lowerBounds as $index => $lower) {
            $upper = $this->lowerBounds[$index + 1] ?? 1.0;
            $bands[] = ['label' => $this->label($lower, $upper), 'from' => $lower, 'to' => $upper];
        }

        return array_reverse($bands);
    }

    private function label(float $from, float $to): string
    {
        return sprintf('%.2f–%.2f', $from, $to);
    }
}
