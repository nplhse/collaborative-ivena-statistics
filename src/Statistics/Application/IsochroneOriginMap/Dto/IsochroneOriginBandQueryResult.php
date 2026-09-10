<?php

declare(strict_types=1);

namespace App\Statistics\Application\IsochroneOriginMap\Dto;

final readonly class IsochroneOriginBandQueryResult
{
    /**
     * @param array<array-key, int> $countsByKey
     */
    public function __construct(
        public array $countsByKey,
    ) {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function countFor(string $key): int
    {
        return $this->countsByKey[$key] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->countsByKey);
    }
}
