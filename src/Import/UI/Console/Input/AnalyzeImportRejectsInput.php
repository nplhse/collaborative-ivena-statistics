<?php

declare(strict_types=1);

namespace App\Import\UI\Console\Input;

use App\Import\Domain\Enum\RejectAnalysisExportFormat;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class AnalyzeImportRejectsInput
{
    #[Option(description: 'Export format: csv, md, json')]
    public RejectAnalysisExportFormat $format = RejectAnalysisExportFormat::Csv;

    #[Option(description: 'Output file path')]
    public string $output = 'var/export/import-reject-analysis.csv';

    #[Option(description: 'Limit to top N groups after sorting')]
    #[Assert\Positive]
    public ?int $limit = null;

    #[Option(description: 'Include example raw row JSON in output', name: 'include-examples')]
    public bool $includeExamples = false;

    #[Option(description: 'Minimum count per group', name: 'min-count')]
    #[Assert\Positive]
    public int $minCount = 1;
}
