<?php

declare(strict_types=1);

namespace App\Statistics\GenericAnalysis\Application\Export;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('statistics.tabular_exporter')]
final class CsvTabularExporter implements TabularExporterInterface
{
    private const string DELIMITER = ',';

    private const string ENCLOSURE = '"';

    private const string ESCAPE = '\\';

    private const string UTF8_BOM = "\xEF\xBB\xBF";

    #[\Override]
    public function supports(string $format): bool
    {
        return 'csv' === $format;
    }

    #[\Override]
    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    #[\Override]
    public function fileExtension(): string
    {
        return 'csv';
    }

    #[\Override]
    public function export(TabularExportDocument $document, $stream): void
    {
        fwrite($stream, self::UTF8_BOM);

        fputcsv(
            $stream,
            array_map(static fn (TabularExportColumn $column): string => $column->label, $document->headers),
            self::DELIMITER,
            self::ENCLOSURE,
            self::ESCAPE,
        );

        foreach ($document->rows as $row) {
            fputcsv(
                $stream,
                array_map($this->formatCell(...), $row),
                self::DELIMITER,
                self::ENCLOSURE,
                self::ESCAPE,
            );
        }

        foreach ($document->footerRows as $footerRow) {
            fputcsv(
                $stream,
                array_map($this->formatCell(...), $footerRow),
                self::DELIMITER,
                self::ENCLOSURE,
                self::ESCAPE,
            );
        }
    }

    private function formatCell(string|int|float|null $value): string
    {
        if (null === $value) {
            return '';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return $value;
    }
}
