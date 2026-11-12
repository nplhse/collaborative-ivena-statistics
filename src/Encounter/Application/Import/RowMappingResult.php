<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

/**
 * Outcome of mapping one data row: a valid visit, a rejected row with its findings, or a row without
 * any time anchor, which is skipped and counted (ADR 028).
 */
final readonly class RowMappingResult
{
    /**
     * @param list<RowError> $errors
     */
    private function __construct(
        public ?EdVisitRow $row,
        public array $errors,
    ) {
    }

    public static function valid(EdVisitRow $row): self
    {
        return new self($row, []);
    }

    /**
     * @param non-empty-list<RowError> $errors
     */
    public static function rejected(array $errors): self
    {
        return new self(null, $errors);
    }

    public static function skipped(): self
    {
        return new self(null, []);
    }

    public function isSkipped(): bool
    {
        return !$this->row instanceof EdVisitRow && [] === $this->errors;
    }
}
