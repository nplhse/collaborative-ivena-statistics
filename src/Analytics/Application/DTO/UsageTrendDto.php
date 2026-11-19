<?php

declare(strict_types=1);

namespace App\Analytics\Application\DTO;

/**
 * Daily, weekly and monthly users per day for the admin usage trend (#1322). Null marks a day that cannot be shown.
 */
final readonly class UsageTrendDto
{
    /**
     * @param list<string>   $labels
     * @param list<int|null> $dau
     * @param list<int|null> $wau
     * @param list<int|null> $mau
     */
    public function __construct(
        public array $labels,
        public array $dau,
        public array $wau,
        public array $mau,
    ) {
    }

    /**
     * Whether a monthly value exists for a day before today, i.e. the rolling history has started.
     */
    public function hasMonthlyHistory(): bool
    {
        return [] !== array_filter(\array_slice($this->mau, 0, -1), static fn (?int $value): bool => null !== $value);
    }

    /**
     * @return array{labels: list<string>, dau: list<int|null>, wau: list<int|null>, mau: list<int|null>}
     */
    public function toChartValue(): array
    {
        return ['labels' => $this->labels, 'dau' => $this->dau, 'wau' => $this->wau, 'mau' => $this->mau];
    }
}
