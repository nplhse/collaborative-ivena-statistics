<?php

declare(strict_types=1);

namespace App\Import\Domain\Enum;

enum RejectAnalysisExportFormat: string
{
    case Csv = 'csv';
    case Markdown = 'md';
    case Json = 'json';
}
