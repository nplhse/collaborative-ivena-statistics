<?php

namespace App\Import\Infrastructure\Adapter;

use App\Import\Application\Contracts\RejectWriterInterface;
use App\Import\Domain\Entity\Import;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

#[AsTaggedItem('import.reject_writer')]
final class SplCsvRejectWriter implements RejectWriterInterface
{
    private ?\SplFileObject $file = null;
    private int $count = 0;
    private ?string $absolutePath = null;

    public function __construct(
        public readonly Filesystem $filesystem,
        #[Autowire('%app.rejects_base_dir%')]
        private readonly string $rejectsBaseDir,
        private readonly string $delimiter = ';',
        private readonly string $enclosure = "\0",
        private readonly string $escape = '\\',
    ) {
    }

    #[\Override]
    public function getType(): string
    {
        return 'csv';
    }

    #[\Override]
    public function start(Import $import): void
    {
        $this->count = 0;

        $subDir = date('Y').'/'.date('m');
        $dirAbs = Path::join($this->rejectsBaseDir, $subDir);

        $this->filesystem->mkdir($dirAbs, 0775);

        $this->absolutePath = Path::join(
            $dirAbs,
            sprintf(
                'alloc_import_%d_rejects_%s.csv',
                (int) $import->getId(),
                date('Ymd_His')
            )
        );

        $this->absolutePath = Path::canonicalize($this->absolutePath);

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
        if (null === $this->file) {
            throw new \LogicException('Reject writer not started. Call start() before write().');
        }

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
    public function getPath(): ?string
    {
        return $this->absolutePath;
    }
}
