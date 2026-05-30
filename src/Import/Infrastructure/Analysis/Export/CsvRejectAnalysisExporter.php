<?php

declare(strict_types=1);

namespace App\Import\Infrastructure\Analysis\Export;

use App\Import\Application\Analysis\DTO\RejectAnalysisResult;

final class CsvRejectAnalysisExporter implements RejectAnalysisExporterInterface
{
    private const string DELIMITER = ',';

    private const string ENCLOSURE = '"';

    private const string ESCAPE = '\\';

    private const array HEADERS = [
        'count',
        'field',
        'rejected_value',
        'reason',
        'example_file',
        'example_line',
        'suggested_transformer_hint',
        'example_raw_row',
    ];

    #[\Override]
    public function supports(string $format): bool
    {
        return 'csv' === $format;
    }

    #[\Override]
    public function export(RejectAnalysisResult $result, string $outputPath): void
    {
        $handle = fopen($outputPath, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot open output file: %s', $outputPath));
        }

        try {
            fputcsv($handle, self::HEADERS, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);

            foreach ($result->groups as $group) {
                fputcsv($handle, [
                    $group->count,
                    $group->field,
                    $group->rejectedValue,
                    $group->reason,
                    $group->exampleFile,
                    $group->exampleLine,
                    $group->suggestedTransformerHint,
                    $group->exampleRawRow,
                ], self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
            }
        } finally {
            fclose($handle);
        }
    }
}
