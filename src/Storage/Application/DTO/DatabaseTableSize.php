<?php

declare(strict_types=1);

namespace App\Storage\Application\DTO;

final readonly class DatabaseTableSize
{
    public function __construct(
        public string $schema,
        public string $table,
        public int $bytes,
    ) {
    }

    public function name(): string
    {
        return 'public' === $this->schema ? $this->table : $this->schema.'.'.$this->table;
    }
}
