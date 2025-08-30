<?php

namespace App\Service\Import\Adapter;

use App\Service\Import\Contracts\RowReaderInterface;

final class InMemoryRowReader implements RowReaderInterface
{
    /** @var array<int,string>|null */
    private ?array $headerRow;

    /** @var array<int,string>|null */
    private ?array $rawHeaderRow;

    /** @var array<int,array<int,string>> */
    private array $numericRows;

    /**
     * @param array<int,array<string,string>> $assocRows
     */
    public static function fromAssocRows(array $assocRows): self
    {
        if ([] === $assocRows) {
            return new self([], []);
        }

        $header = array_keys($assocRows[0]);

        $numeric = [];
        foreach ($assocRows as $row) {
            $values = [];
            foreach ($header as $h) {
                $values[] = isset($row[$h]) ? (string) $row[$h] : '';
            }
            $numeric[] = $values;
        }

        return new self($header, $numeric);
    }

    /**
     * @param array<int,string>            $header
     * @param array<int,array<int,string>> $numericRows
     */
    public function __construct(?array $header = null, array $numericRows = [])
    {
        $this->headerRow = $header ?? null;
        $this->rawHeaderRow = $header ?? null; // no separate “raw” in-memory; mirror normalized header
        $this->numericRows = $numericRows;
    }

    /** @return array<int,string>|null */
    public function header(): ?array
    {
        return $this->headerRow;
    }

    /** @return array<int,string>|null */
    public function rawHeader(): ?array
    {
        return $this->rawHeaderRow;
    }

    /** @return iterable<array<int,string>> */
    public function rows(): iterable
    {
        // raw numeric rows (no header line)
        foreach ($this->numericRows as $row) {
            yield $row;
        }
    }

    /** @return iterable<array<string,string>> */
    public function rowsAssoc(): iterable
    {
        if (null === $this->headerRow) {
            throw new \RuntimeException('rowsAssoc() requires a header. Use fromAssocRows() or pass a header into the constructor.');
        }

        $count = \count($this->headerRow);
        foreach ($this->numericRows as $row) {
            $values = \array_slice($row, 0, $count);
            if (\count($values) < $count) {
                $values += \array_fill(\count($values), $count - \count($values), '');
            }
            yield \array_combine($this->headerRow, $values);
        }
    }
}
