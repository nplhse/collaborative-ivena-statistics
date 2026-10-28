<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

/**
 * A feature that a case may lack: `present` is the share of cases that have it, `shares` the mix among them.
 */
final readonly class OptionalShares
{
    /**
     * @param array<array-key, float> $shares
     */
    public function __construct(
        public float $present,
        public array $shares,
    ) {
    }

    /**
     * @param array{present: float|int, shares: array<array-key, float|int>} $data
     */
    public static function fromArray(array $data): self
    {
        return new self((float) $data['present'], array_map(floatval(...), $data['shares']));
    }

    /**
     * @return array{present: float, shares: array<array-key, float>}
     */
    public function toArray(): array
    {
        return ['present' => $this->present, 'shares' => $this->shares];
    }
}
