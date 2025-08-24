<?php

namespace App\Service\Import\Adapter;

use App\Service\Import\Contracts\RejectWriterInterface;

final class CsvRejectWriter implements RejectWriterInterface
{
    private \SplFileObject $file;
    private int $count = 0;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ';',
        private readonly string $enclosure = "\0",
        private readonly string $escape = '\\',
    ) {
        if ('' === $this->path) {
            throw new \InvalidArgumentException('RejectWriter path must not be empty');
        }

        $dir = \dirname($this->path);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $dir));
            }
        }

        $this->file = new \SplFileObject($this->path, 'w');
        $this->file->setCsvControl($this->delimiter, $this->enclosure, $this->escape);
        $this->file->fputcsv(['line', 'error_messages', 'row_json'], $this->delimiter, $this->enclosure, $this->escape);
    }

    #[\Override]
    public function write(array $row, array $messages, ?int $line = null): void
    {
        $this->file->fputcsv(
            [$line ?? '', implode(' | ', $messages), json_encode($row, JSON_UNESCAPED_UNICODE)],
            $this->delimiter, $this->enclosure, $this->escape
        );
        ++$this->count;
    }

    #[\Override]
    public function close(): void
    {
    }

    #[\Override]
    public function getCount(): int
    {
        return $this->count;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->path;
    }
}
