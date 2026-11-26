<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure\Backup;

use App\Storage\Application\Backup\BackupFailed;
use App\Storage\Application\Contract\ArchiveReaderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Reads a gzip-compressed tar archive in PHP, without extracting it (ADR 038, "Verify").
 *
 * Every entry is checked before a restore would extract it: only regular files and directories with relative
 * paths and without ".." are accepted; links, devices and FIFOs fail the archive. Regular files are hashed while
 * the archive is streamed, so the content is compared with the checksum list without touching the disk.
 *
 * Understands the headers GNU tar and bsdtar write: ustar (with prefix), pax extended headers (`path`, `size`)
 * and GNU long names. Header checksums are checked, so a damaged header is reported, not misread.
 *
 * @psalm-suppress UnusedClass Wired via #[AsAlias] for ArchiveReaderInterface.
 */
#[AsAlias(ArchiveReaderInterface::class)]
final readonly class TarArchiveReader implements ArchiveReaderInterface
{
    private const int BLOCK = 512;

    private const int CHUNK = 1048576;

    #[\Override]
    public function entries(string $archiveFile): array
    {
        $stream = @gzopen($archiveFile, 'rb');
        if (false === $stream) {
            throw new BackupFailed(sprintf('%s cannot be opened.', basename($archiveFile)));
        }

        $entries = [];
        $longName = null;
        $pax = [];

        try {
            while (true) {
                $header = $this->read($stream, self::BLOCK);
                if (str_repeat("\0", self::BLOCK) === $header) {
                    break;
                }

                $this->checkHeader($header);
                $type = $header[156];
                $headerSize = $this->size($header);

                if ('x' === $type || 'g' === $type || 'L' === $type || 'K' === $type) {
                    $content = $this->read($stream, $headerSize);
                    $this->skipPadding($stream, $headerSize);
                    match ($type) {
                        'x' => $pax = $this->paxRecords($content),
                        'L' => $longName = rtrim($content, "\0"),
                        'K' => throw new BackupFailed('The archive contains a link.', 'A backup archive holds regular files only; it may have been changed.'),
                        default => null,
                    };

                    continue;
                }

                $size = isset($pax['size']) ? (int) $pax['size'] : $headerSize;
                $path = $pax['path'] ?? $longName ?? $this->name($header);
                $longName = null;
                $pax = [];
                $relative = $this->relative($path);

                if ('5' === $type) {
                    continue;
                }
                if ('0' !== $type && "\0" !== $type && '7' !== $type) {
                    throw new BackupFailed(sprintf('The archive contains %s, which is not a regular file.', '' === $relative ? '.' : $relative), 'A backup archive holds regular files only; it may have been changed.');
                }
                if ('' === $relative || \array_key_exists($relative, $entries)) {
                    throw new BackupFailed(sprintf('The archive contains the file "%s" more than once or without a name.', $relative));
                }

                $entries[$relative] = ['size' => $size, 'sha256' => $this->hash($stream, $size)];
                $this->skipPadding($stream, $size);
            }
        } finally {
            gzclose($stream);
        }

        ksort($entries, \SORT_STRING);

        return $entries;
    }

    /**
     * @param resource $stream
     */
    private function read($stream, int $length): string
    {
        $data = '';
        while (\strlen($data) < $length) {
            $chunk = gzread($stream, min(self::CHUNK, $length - \strlen($data)));
            if (false === $chunk || '' === $chunk) {
                throw new BackupFailed('The archive ends unexpectedly.', 'The file is truncated or damaged; use another backup.');
            }
            $data .= $chunk;
        }

        return $data;
    }

    /**
     * @param resource $stream
     */
    private function hash($stream, int $size): string
    {
        $context = hash_init('sha256');
        $remaining = $size;
        while ($remaining > 0) {
            $chunk = $this->read($stream, min(self::CHUNK, $remaining));
            hash_update($context, $chunk);
            $remaining -= \strlen($chunk);
        }

        return hash_final($context);
    }

    /**
     * @param resource $stream
     */
    private function skipPadding($stream, int $size): void
    {
        $padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
        if ($padding > 0) {
            $this->read($stream, $padding);
        }
    }

    private function checkHeader(string $header): void
    {
        $stored = trim(substr($header, 148, 8), "\0 ");
        $sum = 0;
        for ($i = 0; $i < self::BLOCK; ++$i) {
            $sum += $i >= 148 && $i < 156 ? 32 : \ord($header[$i]);
        }

        if (1 !== preg_match('/^[0-7]+$/', $stored) || (int) octdec($stored) !== $sum) {
            throw new BackupFailed('The archive has a damaged header.', 'The file is damaged; use another backup.');
        }
    }

    private function size(string $header): int
    {
        $field = substr($header, 124, 12);
        if (0 !== (\ord($field[0]) & 0x80)) {
            // Base-256 for files of 8 GiB and more (GNU tar).
            $size = \ord($field[0]) & 0x7F;
            for ($i = 1; $i < 12; ++$i) {
                $size = ($size << 8) | \ord($field[$i]);
            }

            return $size;
        }

        $octal = trim($field, "\0 ");

        return '' === $octal ? 0 : (int) octdec($octal);
    }

    private function name(string $header): string
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        if ("ustar\0" === substr($header, 257, 6)) {
            $prefix = rtrim(substr($header, 345, 155), "\0");
            if ('' !== $prefix) {
                $name = $prefix.'/'.$name;
            }
        }

        return $name;
    }

    /**
     * @return array<string, string>
     */
    private function paxRecords(string $content): array
    {
        $records = [];
        $offset = 0;
        while ($offset < \strlen($content) && 1 === preg_match('/\G(\d+) /', $content, $match, 0, $offset)) {
            $length = (int) $match[1];
            if ($length <= \strlen($match[0])) {
                break;
            }
            $record = substr($content, $offset + \strlen($match[0]), $length - \strlen($match[0]) - 1);
            $separator = strpos($record, '=');
            if (false !== $separator) {
                $records[substr($record, 0, $separator)] = substr($record, $separator + 1);
            }
            $offset += $length;
        }

        return $records;
    }

    /**
     * The path relative to the archive root, without "./" and a trailing slash.
     *
     * @throws BackupFailed for absolute paths and ".." segments
     */
    private function relative(string $path): string
    {
        if (str_starts_with($path, '/') || str_contains($path, "\0") || \in_array('..', explode('/', $path), true)) {
            throw new BackupFailed(sprintf('The archive contains the unsafe path "%s".', $path), 'A restore would write outside its directory; the archive may have been changed.');
        }

        $relative = (string) preg_replace('#^(\./)+#', '', rtrim($path, '/'));

        return '.' === $relative ? '' : $relative;
    }
}
