<?php

declare(strict_types=1);

namespace App\Shared\Application\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvStreamExportResponseFactory
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * @param callable(resource): int $writer receives php://output stream, returns row count
     */
    public function create(string $filename, callable $writer): StreamedResponse
    {
        return new StreamedResponse(
            function () use ($writer): void {
                $stream = fopen('php://output', 'w');
                if (false === $stream) {
                    throw new \RuntimeException('Cannot open output stream for CSV export.');
                }

                try {
                    fwrite($stream, self::UTF8_BOM);
                    $writer($stream);
                } finally {
                    fclose($stream);
                }
            },
            StreamedResponse::HTTP_OK,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }

    /**
     * @param resource                    $stream
     * @param list<string|int|float|null> $cells
     */
    public function writeRow($stream, array $cells): void
    {
        fputcsv(
            $stream,
            array_map(static fn (string|int|float|null $value): string => match (true) {
                null === $value => '',
                \is_int($value) => (string) $value,
                \is_float($value) => rtrim(rtrim(sprintf('%.10F', $value), '0'), '.'),
                default => $value,
            }, $cells),
            ',',
            '"',
            '\\',
        );
    }
}
