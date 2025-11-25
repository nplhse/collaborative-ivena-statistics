<?php

namespace App\Import\Infrastructure\Adapter;

use Psr\Log\LoggerInterface;

final readonly class SplCsvStreamFactory
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function openUtf8(
        string $path,
        string $sourceEncoding,
        string $delimiter = ';',
        string $enclosure = '"',
        string $escape = '\\',
    ): \SplFileObject {
        if ('UTF-8' === $sourceEncoding) {
            $f = new \SplFileObject($path, 'r');
            $f->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
            $f->setCsvControl($delimiter, $enclosure, $escape);
            $this->stripBomAtStreamStart($f);

            $this->logger->info('CSV opened as UTF-8 (no conversion).', [
                'path' => $path,
                'detected_encoding' => $sourceEncoding,
                'used_filter' => false,
            ]);

            return $f;
        }

        $from = rawurlencode('ISO-8859-1');
        $to = rawurlencode('UTF-8');
        $uri = sprintf('php://filter/read=convert.iconv.%s.%s/resource=%s', $from, $to, $path);

        $f = new \SplFileObject($uri, 'r');
        $f->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY | \SplFileObject::DROP_NEW_LINE);
        $f->setCsvControl($delimiter, $enclosure, $escape);

        $this->stripBomAtStreamStart($f);

        $this->logger->info('CSV opened with iconv filter (ISO-8859-1 → UTF-8).', [
            'path' => $path,
            'detected_encoding' => $sourceEncoding,
            'used_filter' => true,
        ]);

        return $f;
    }

    private function stripBomAtStreamStart(\SplFileObject $fileile): void
    {
        $fileile->rewind();
        $prefix = $fileile->fread(3);

        if ("\xEF\xBB\xBF" !== $prefix) {
            $fileile->rewind();
        }
    }
}
