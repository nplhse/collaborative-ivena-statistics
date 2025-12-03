<?php

namespace App\Import\Application\Contracts;

use App\Import\Domain\Entity\Import;

interface RejectWriterInterface
{
    public function start(Import $import): void;

    /**
     * @param array<string,string|null> $row
     * @param list<string>              $messages
     * */
    public function write(array $row, array $messages, ?int $line = null): void;

    public function close(): void;

    public function getCount(): int;

    public function getPath(): ?string;

    public function getType(): string;
}
