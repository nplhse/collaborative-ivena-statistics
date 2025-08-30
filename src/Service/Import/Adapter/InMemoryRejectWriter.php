<?php

namespace App\Service\Import\Adapter;

use App\Service\Import\Contracts\RejectWriterInterface;

final class InMemoryRejectWriter implements RejectWriterInterface
{
    /** @var array<int,array{line:int|null,messages:array<int,string>,row:array<string,string>}> */
    private array $records = [];

    private int $count = 0;

    #[\Override]
    public function write(array $row, array $messages, ?int $line = null): void
    {
        $assocRow = [];
        foreach ($row as $k => $v) {
            $assocRow[$k] = $v ?? '';
        }

        $this->records[] = [
            'line' => $line,
            'messages' => $messages,
            'row' => $assocRow,
        ];
        ++$this->count;
    }

    #[\Override]
    public function close(): void
    {
        // no-op for memory
    }

    #[\Override]
    public function getCount(): int
    {
        return $this->count;
    }

    #[\Override]
    public function getPath(): ?string
    {
        // no real file – keep null or return a sentinel like 'memory://rejects'
        return null;
    }

    /** @return array<int,array{line:int|null,messages:array<int,string>,row:array<string,string>}> */
    public function all(): array
    {
        return $this->records;
    }

    public function toCsv(string $delimiter = ';'): string
    {
        $lines = [];
        $lines[] = implode($delimiter, ['line', 'error_messages', 'row_json']);

        foreach ($this->records as $rec) {
            $line = (string) ($rec['line'] ?? '');
            $msgs = implode(' | ', $rec['messages']);
            $json = \json_encode($rec['row'], \JSON_UNESCAPED_UNICODE);
            $lines[] = implode($delimiter, [$line, $msgs, $json]);
        }

        return implode("\n", $lines)."\n";
    }
}
