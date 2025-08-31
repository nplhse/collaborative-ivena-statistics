<?php

namespace App\Service\Import\Adapter;

use App\Service\Import\Contracts\RejectWriterInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

final class SplCsvRejectWriter implements RejectWriterInterface
{
    private \SplFileObject $file;
    private int $count = 0;
    private string $absolutePath;

    public function __construct(
        string $absolutePath,
        readonly Filesystem $filesystem,
        private readonly string $delimiter = ';',
        private readonly string $enclosure = "\0",
        private readonly string $escape = '\\',
    ) {
        $this->absolutePath = Path::canonicalize($absolutePath);
        $this->filesystem->mkdir(\dirname($this->absolutePath), 0775);

        $this->file = new \SplFileObject($this->absolutePath, 'w');
        $this->file->setCsvControl($this->delimiter, $this->enclosure, $this->escape);

        $this->file->fputcsv(
            ['line', 'error_messages', 'row_json'],
            $this->delimiter,
            $this->enclosure,
            $this->escape
        );
    }

    #[\Override]
    public function write(array $row, array $messages, ?int $line = null): void
    {
        $this->file->fputcsv(
            [
                $line ?? '',
                implode(' | ', $messages),
                json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ],
            $this->delimiter,
            $this->enclosure,
            $this->escape
        );

        ++$this->count;
    }

    #[\Override]
    public function close(): void
    {
        // SplFileObject closes automatically on destruction
    }

    #[\Override]
    public function getCount(): int
    {
        return $this->count;
    }

    #[\Override]
    public function getPath(): string
    {
        return $this->absolutePath;
    }
}
