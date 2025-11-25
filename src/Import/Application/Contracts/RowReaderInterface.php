<?php

namespace App\Import\Application\Contracts;

interface RowReaderInterface
{
    /**
     * @return iterable<array<int,string>>
     */
    public function rows(): iterable;

    /**
     * @return array<int,string>|null
     */
    public function header(): ?array;

    /**
     * @return iterable<array<string,string>>
     */
    public function rowsAssoc(): iterable;
}
