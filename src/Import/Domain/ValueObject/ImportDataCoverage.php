<?php

declare(strict_types=1);

namespace App\Import\Domain\ValueObject;

/**
 * Compact summary of the period an import's records cover, stored on `import.data_coverage`.
 *
 * Calculated once when the import finishes, before deduplication, so it describes the file
 * and does not change when later imports replace some of its rows.
 *
 * - Allocation imports: `records` are allocations, `dailyCounts` allocations per `created_at` day.
 * - Closure imports: `records` are closure intervals, `dailyCounts` intervals active per day.
 *
 * Timestamps are naive wall-clock values as IVENA delivers them. Days are `Y-m-d` strings.
 *
 * @psalm-type ImportDataCoverageData = array{
 *     version: int,
 *     records: int,
 *     first_at: string|null,
 *     last_at: string|null,
 *     daily_counts: array<string, int>,
 * }
 */
final readonly class ImportDataCoverage
{
    public const int VERSION = 1;

    private const string DAY_FORMAT = 'Y-m-d';

    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /** @var array<string, int> */
    public array $dailyCounts;

    /**
     * @param array<string, int> $dailyCounts day => records, only days with records
     */
    public function __construct(
        public int $records,
        public ?\DateTimeImmutable $firstAt,
        public ?\DateTimeImmutable $lastAt,
        array $dailyCounts,
    ) {
        if ($records < 0) {
            throw new \InvalidArgumentException('Coverage records must not be negative.');
        }
        foreach ($dailyCounts as $day => $count) {
            if (!$this->isDay((string) $day) || $count < 1) {
                throw new \InvalidArgumentException(\sprintf('Invalid coverage day "%s" => %d.', $day, $count));
            }
        }
        $empty = [] === $dailyCounts;
        if ($empty !== (!$firstAt instanceof \DateTimeImmutable) || $empty !== (!$lastAt instanceof \DateTimeImmutable)) {
            throw new \InvalidArgumentException('Coverage needs first and last timestamps exactly when it has days.');
        }
        if ($firstAt instanceof \DateTimeImmutable && $lastAt instanceof \DateTimeImmutable && $lastAt < $firstAt) {
            throw new \InvalidArgumentException('Coverage must not end before it starts.');
        }
        ksort($dailyCounts, \SORT_STRING);
        $this->dailyCounts = $dailyCounts;
    }

    public static function empty(): self
    {
        return new self(0, null, null, []);
    }

    public function isEmpty(): bool
    {
        return [] === $this->dailyCounts;
    }

    public function firstDay(): ?\DateTimeImmutable
    {
        $day = array_key_first($this->dailyCounts);

        return null === $day ? null : $this->day((string) $day);
    }

    public function lastDay(): ?\DateTimeImmutable
    {
        $day = array_key_last($this->dailyCounts);

        return null === $day ? null : $this->day((string) $day);
    }

    /**
     * Calendar days from the first to the last day with records, both inclusive.
     */
    public function periodDays(): int
    {
        $first = $this->firstDay();
        $last = $this->lastDay();
        if (!$first instanceof \DateTimeImmutable || !$last instanceof \DateTimeImmutable) {
            return 0;
        }

        return (int) $first->diff($last)->days + 1;
    }

    public function daysWithData(): int
    {
        return \count($this->dailyCounts);
    }

    public function daysWithoutData(): int
    {
        return $this->periodDays() - $this->daysWithData();
    }

    public function countOn(\DateTimeImmutable $day): int
    {
        return $this->dailyCounts[$day->format(self::DAY_FORMAT)] ?? 0;
    }

    public function maxDailyCount(): int
    {
        return [] === $this->dailyCounts ? 0 : max($this->dailyCounts);
    }

    /**
     * Days without records between the first and the last day, grouped into intervals.
     *
     * @return list<ImportDataCoverageGap>
     */
    public function gaps(): array
    {
        $gaps = [];
        $previous = null;
        foreach (array_keys($this->dailyCounts) as $key) {
            $day = $this->day((string) $key);
            if ($previous instanceof \DateTimeImmutable) {
                $expected = $previous->modify('+1 day');
                if ($day > $expected) {
                    $gaps[] = new ImportDataCoverageGap($expected, $day->modify('-1 day'));
                }
            }
            $previous = $day;
        }

        return $gaps;
    }

    /**
     * @return ImportDataCoverageData
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'records' => $this->records,
            'first_at' => $this->firstAt?->format(self::TIMESTAMP_FORMAT),
            'last_at' => $this->lastAt?->format(self::TIMESTAMP_FORMAT),
            'daily_counts' => $this->dailyCounts,
        ];
    }

    /**
     * Reads a stored summary. Unknown versions and malformed data yield null, which the
     * detail page shows like a summary that was not calculated yet.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!\is_array($data) || self::VERSION !== ($data['version'] ?? null) || !\is_int($data['records'] ?? null) || !\is_array($data['daily_counts'] ?? null)) {
            return null;
        }
        $dailyCounts = [];
        foreach ($data['daily_counts'] as $day => $count) {
            if (!\is_int($count)) {
                return null;
            }
            $dailyCounts[(string) $day] = $count;
        }
        try {
            return new self(
                $data['records'],
                self::timestamp($data['first_at'] ?? null),
                self::timestamp($data['last_at'] ?? null),
                $dailyCounts,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private static function timestamp(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new \InvalidArgumentException('Coverage timestamps must be strings.');
        }
        $parsed = \DateTimeImmutable::createFromFormat('!'.self::TIMESTAMP_FORMAT, $value);
        if (!$parsed instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException(\sprintf('Invalid coverage timestamp "%s".', $value));
        }

        return $parsed;
    }

    private function isDay(string $day): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!'.self::DAY_FORMAT, $day, new \DateTimeZone('UTC'));

        return $parsed instanceof \DateTimeImmutable && $parsed->format(self::DAY_FORMAT) === $day;
    }

    /**
     * Days are calculated in UTC so a DST switch never shortens or lengthens one.
     */
    private function day(string $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable($day, new \DateTimeZone('UTC'));
    }
}
