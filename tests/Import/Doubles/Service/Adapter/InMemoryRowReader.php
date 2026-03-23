<?php

namespace App\Tests\Import\Doubles\Service\Adapter;

use App\Import\Application\Contracts\RowReaderInterface;

final readonly class InMemoryRowReader implements RowReaderInterface
{
    /** @var list<string>|null */
    private ?array $headerRow;

    /**
     * @param list<string>|null  $rawHeaderRow
     * @param list<list<string>> $numericRows
     */
    public function __construct(private ?array $rawHeaderRow = null, private array $numericRows = [])
    {
        $this->headerRow = null === $this->rawHeaderRow ? null : \array_map($this->normalizeHeader(...), $this->rawHeaderRow);
    }

    /**
     * @param array<int,array<string,string>> $assocRows
     */
    public static function fromAssocRows(array $assocRows): self
    {
        if ([] === $assocRows) {
            return new self([], []);
        }

        $header = \array_keys($assocRows[0]);
        $numeric = [];

        foreach ($assocRows as $row) {
            $values = [];
            foreach ($header as $h) {
                $values[] = $row[$h] ?? '';
            }
            $numeric[] = $values;
        }

        return new self($header, $numeric);
    }

    #[\Override]
    /** @return list<string>|null */
    public function header(): ?array
    {
        return $this->headerRow;
    }

    /** @return list<string>|null */
    public function rawHeader(): ?array
    {
        return $this->rawHeaderRow;
    }

    #[\Override]
    /** @return iterable<list<string>> */
    public function rows(): iterable
    {
        foreach ($this->numericRows as $row) {
            yield $row;
        }
    }

    #[\Override]
    /** @return iterable<array<string,string>> */
    public function rowsAssoc(): iterable
    {
        if (null === $this->headerRow) {
            throw new \RuntimeException('rowsAssoc() requires a header.');
        }

        $count = \count($this->headerRow);
        foreach ($this->numericRows as $row) {
            $values = \array_slice($row, 0, $count);
            if (\count($values) < $count) {
                $values += \array_fill(\count($values), $count - \count($values), '');
            }

            $assoc = \array_combine($this->headerRow, $values);
            yield $assoc;
        }
    }

    private function normalizeHeader(string $header): string
    {
        $header = \trim($header);
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'];
        $header = \strtr($header, $map);
        $header = \preg_replace('/[^A-Za-z0-9]+/', ' ', $header) ?? '';
        $header = \trim(\preg_replace('/\s+/', ' ', $header) ?? '');

        if ('' === $header) {
            return '';
        }

        return \implode('_', \explode(' ', \strtolower($header)));
    }
}
