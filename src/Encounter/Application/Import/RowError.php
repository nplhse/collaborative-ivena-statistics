<?php

declare(strict_types=1);

namespace App\Encounter\Application\Import;

use App\Encounter\Domain\Enum\EncounterRowError;

/**
 * One finding of a rejected row: field and error code, never the cell value.
 */
final readonly class RowError
{
    public function __construct(
        public string $field,
        public EncounterRowError $error,
    ) {
    }

    public function describe(): string
    {
        return sprintf('%s: %s', $this->field, $this->error->value);
    }
}
