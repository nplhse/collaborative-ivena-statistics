<?php

declare(strict_types=1);

namespace App\Encounter\Contract\Import;

/**
 * The import a schema handler writes for: hospital and the key generation checked by the core.
 */
final readonly class SchemaWriteContext
{
    public function __construct(
        public int $hospitalId,
        public int $importId,
        public int $keyGeneration,
    ) {
    }
}
