<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

/**
 * Outcome of mapping one data row: a valid row, a rejected row with its findings, or a row the schema
 * skips and only counts (for `ed_visit`, a row without any time anchor, ADR 029). Consumers that need
 * the concrete row class check it with instanceof.
 */
final readonly class RowMappingResult
{
    /**
     * @param list<RowError> $errors
     */
    private function __construct(
        public ?EncounterRecordRow $row,
        public array $errors,
    ) {
    }

    public static function valid(EncounterRecordRow $row): self
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
        return !$this->row instanceof EncounterRecordRow && [] === $this->errors;
    }
}
