<?php

declare(strict_types=1);

namespace App\Research\Application\Export;

/**
 * Summary of a dry run of an export query (ADR 045), stored with the query version and shown only to administrators.
 * The reserved hospital column is never part of the columns or the sample.
 *
 * @psalm-suppress PossiblyUnusedProperty Read by the template.
 */
final readonly class DryRunResult
{
    /**
     * @param list<string>                     $columns
     * @param list<array<string, scalar|null>> $sample           the first rows
     * @param list<array<string, scalar|null>> $belowMinimumRows the first groups below the minimum
     */
    public function __construct(
        public \DateTimeImmutable $ranAt,
        public int $durationMs,
        public int $rows = 0,
        public array $columns = [],
        public array $sample = [],
        public int $hospitals = 0,
        public int $belowMinimum = 0,
        public array $belowMinimumRows = [],
        public bool $truncated = false,
        public ?string $error = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ran_at' => $this->ranAt->format(\DATE_ATOM),
            'duration_ms' => $this->durationMs,
            'rows' => $this->rows,
            'columns' => $this->columns,
            'sample' => $this->sample,
            'hospitals' => $this->hospitals,
            'below_minimum' => $this->belowMinimum,
            'below_minimum_rows' => $this->belowMinimumRows,
            'truncated' => $this->truncated,
            'error' => $this->error,
        ];
    }
}
